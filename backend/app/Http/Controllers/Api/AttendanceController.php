<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\FingerprintScan;
use App\Models\Holiday;
use App\Services\ActivityLogger;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceStatusResolver;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceStatusResolver $statusResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $month = max(1, min(12, $request->integer('month', now()->month)));
        $year = max(2000, min(2100, $request->integer('year', now()->year)));
        $employeeIds = collect((array) $request->input('employee_ids', []))
            ->filter(fn ($employeeId) => is_numeric($employeeId) && (int) $employeeId > 0)
            ->map(fn ($employeeId) => (int) $employeeId)
            ->unique()
            ->take(100)
            ->values();
        $this->ensureAbsentRecords($month, $year, $employeeIds);

        $records = AttendanceRecord::query()
            ->with('employee.categories:id,name,group,active')
            ->whereHas('employee')
            ->whereYear('attendance_date', $year)
            ->whereMonth('attendance_date', $month)
            ->when($employeeIds->isNotEmpty(), fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->when($request->integer('employee_id'), fn ($query, $employeeId) => $query->where('employee_id', $employeeId))
            ->when($request->integer('category_id'), fn ($query, $categoryId) => $query->whereHas('employee.categories', fn ($categoryQuery) => $categoryQuery->whereKey($categoryId)))
            ->orderBy('attendance_date')
            ->orderBy('employee_id')
            ->get();
        $scansByDay = $this->scansByDay($records, $month, $year);
        $metricsByRecord = $this->calculator->evaluateMany($records);
        $serializedRecords = $records->map(fn (AttendanceRecord $record) => $this->serialize(
            $record,
            $scansByDay->get($this->scanKey($record->employee_id, $record->attendance_date->toDateString()), collect()),
            $metricsByRecord->get($record->id),
        ));

        return response()->json(['data' => $serializedRecords, 'meta' => ['month' => $month, 'year' => $year]]);
    }

    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $exists = AttendanceRecord::query()
            ->where('employee_id', $validated['employee_id'])
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->exists();

        if ($exists) {
            ActivityLogger::error('attendance.create_failed', 'Kehadiran pada tanggal tersebut sudah tersedia.');

            return response()->json([
                'message' => 'Kehadiran pada tanggal tersebut sudah tersedia.',
                'errors' => ['attendance_date' => ['Pegawai sudah memiliki data kehadiran pada tanggal tersebut.']],
            ], 422);
        }

        $attachment = $request->file('attachment');
        unset($validated['attachment']);
        $storedPath = null;

        try {
            $record = DB::transaction(function () use ($validated, $attachment, &$storedPath): AttendanceRecord {
                $payload = $this->manualPayload($validated);
                if ($attachment) {
                    $storedPath = $this->storeAttachment($attachment);
                    $payload = [...$payload, ...$this->attachmentPayload($attachment, $storedPath)];
                }

                return AttendanceRecord::create($payload);
            });
            ActivityLogger::success('attendance.created', 'Data kehadiran berhasil ditambahkan.', $record);
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            ActivityLogger::error('attendance.create_failed', 'Data kehadiran gagal ditambahkan.', metadata: ['error' => $exception->getMessage()]);
            throw $exception;
        }

        return response()->json(['message' => 'Data kehadiran berhasil ditambahkan.', 'data' => $this->serialize($record->load('employee.categories'), $this->scansForRecord($record))], 201);
    }

    public function show(AttendanceRecord $attendance): JsonResponse
    {
        return response()->json(['data' => $this->serialize($attendance->load('employee.categories'), $this->scansForRecord($attendance))]);
    }

    public function update(UpdateAttendanceRequest $request, AttendanceRecord $attendance): JsonResponse
    {
        $validated = $request->validated();
        $duplicate = AttendanceRecord::query()
            ->where('employee_id', $validated['employee_id'])
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->whereKeyNot($attendance->id)
            ->exists();

        if ($duplicate) {
            ActivityLogger::error('attendance.update_failed', 'Kehadiran pada tanggal tersebut sudah tersedia.', $attendance);

            return response()->json([
                'message' => 'Kehadiran pada tanggal tersebut sudah tersedia.',
                'errors' => ['attendance_date' => ['Pegawai sudah memiliki data kehadiran pada tanggal tersebut.']],
            ], 422);
        }

        $attachment = $request->file('attachment');
        unset($validated['attachment']);
        $newPath = null;
        $oldPath = $attendance->attachment_path;

        try {
            DB::transaction(function () use ($attendance, $validated, $attachment, &$newPath): void {
                $payload = $this->manualPayload($validated);
                if ($attachment) {
                    $newPath = $this->storeAttachment($attachment);
                    $payload = [...$payload, ...$this->attachmentPayload($attachment, $newPath)];
                }
                $attendance->update($payload);
            });

            if ($newPath && $oldPath && $oldPath !== $newPath) {
                Storage::disk('local')->delete($oldPath);
            }
            ActivityLogger::success('attendance.updated', 'Data kehadiran berhasil diperbarui.', $attendance);
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            ActivityLogger::error('attendance.update_failed', 'Data kehadiran gagal diperbarui.', $attendance, ['error' => $exception->getMessage()]);
            throw $exception;
        }

        $freshAttendance = $attendance->fresh()->load('employee.categories');

        return response()->json(['message' => 'Data kehadiran berhasil diperbarui.', 'data' => $this->serialize($freshAttendance, $this->scansForRecord($freshAttendance))]);
    }

    public function destroy(AttendanceRecord $attendance): JsonResponse
    {
        $attachmentPath = $attendance->attachment_path;
        $attendance->delete();
        if ($attachmentPath) {
            Storage::disk('local')->delete($attachmentPath);
        }
        ActivityLogger::success('attendance.deleted', 'Data kehadiran berhasil dihapus.', $attendance);

        return response()->json(['message' => 'Data kehadiran berhasil dihapus.']);
    }

    public function attachment(AttendanceRecord $attendance): BinaryFileResponse|JsonResponse
    {
        $disk = Storage::disk('local');
        if (! $attendance->attachment_path || ! $disk->exists($attendance->attachment_path)) {
            return response()->json(['message' => 'Lampiran tidak ditemukan.'], 404);
        }

        ActivityLogger::success('attendance.attachment_viewed', 'Lampiran kehadiran dilihat.', $attendance);
        $filename = $attendance->attachment_name ?: basename($attendance->attachment_path);
        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $filename, 'lampiran');

        return response()->file($disk->path($attendance->attachment_path), [
            'Content-Type' => $attendance->attachment_mime_type ?: 'application/octet-stream',
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function manualPayload(array $validated): array
    {
        return [
            ...$validated,
            'manual_status' => $validated['status'],
            'source' => 'manual',
            'incomplete' => filled($validated['check_in'] ?? null) && blank($validated['check_out'] ?? null),
        ];
    }

    /** @param array<string, bool|int|null>|null $metrics */
    private function serialize(AttendanceRecord $record, ?Collection $scans = null, ?array $metrics = null): array
    {
        return [
            ...$record->toArray(),
            'effective_status' => $this->statusResolver->effectiveStatus($record),
            'machine_location' => $this->machineLocation($scans ?? collect()),
            'attachment' => $record->attachment_path ? [
                'name' => $record->attachment_name,
                'mime_type' => $record->attachment_mime_type,
                'size' => $record->attachment_size,
                'url' => "attendances/{$record->id}/attachment",
            ] : null,
            'metrics' => $metrics ?? $this->calculator->evaluate($record),
        ];
    }

    /**
     * @param  Collection<int, AttendanceRecord>  $records
     * @return Collection<string, Collection<int, FingerprintScan>>
     */
    private function scansByDay(Collection $records, int $month, int $year): Collection
    {
        $employeeIds = $records->pluck('employee_id')->unique()->values();
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Jakarta')->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        return FingerprintScan::query()
            ->with('attendanceMachine:id,name,serial_number,location,active')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('scanned_at', [$periodStart, $periodEnd])
            ->orderBy('scanned_at')
            ->get()
            ->groupBy(fn (FingerprintScan $scan) => $this->scanKey($scan->employee_id, $scan->scanned_at->toDateString()));
    }

    /** @return Collection<int, FingerprintScan> */
    private function scansForRecord(AttendanceRecord $record): Collection
    {
        return FingerprintScan::query()
            ->with('attendanceMachine:id,name,serial_number,location,active')
            ->where('employee_id', $record->employee_id)
            ->whereDate('scanned_at', $record->attendance_date->toDateString())
            ->orderBy('scanned_at')
            ->get();
    }

    /** @param Collection<int, FingerprintScan> $scans */
    private function machineLocation(Collection $scans): ?string
    {
        if ($scans->isEmpty()) {
            return null;
        }

        /** @var FingerprintScan $firstScan */
        $firstScan = $scans->first();
        /** @var FingerprintScan $lastScan */
        $lastScan = $scans->last();
        $firstLocation = $this->machineLabel($firstScan);
        $lastLocation = $this->machineLabel($lastScan);

        if ($firstScan->device_serial === $lastScan->device_serial) {
            return $firstLocation;
        }

        return "Masuk: {$firstLocation} · Pulang: {$lastLocation}";
    }

    private function machineLabel(FingerprintScan $scan): string
    {
        if ($scan->attendanceMachine) {
            return "{$scan->attendanceMachine->name} — {$scan->attendanceMachine->location}";
        }

        return $scan->device_serial
            ? "SN {$scan->device_serial} — belum terdaftar"
            : 'Mesin tidak diketahui';
    }

    private function scanKey(int $employeeId, string $date): string
    {
        return "{$employeeId}|{$date}";
    }

    private function storeAttachment(UploadedFile $file): string
    {
        $path = $file->store('attendance-attachments', 'local');
        if (! $path) {
            throw new \RuntimeException('Lampiran gagal disimpan.');
        }

        return $path;
    }

    /**
     * @return array{attachment_path: string, attachment_name: string, attachment_mime_type: string, attachment_size: int}
     */
    private function attachmentPayload(UploadedFile $file, string $path): array
    {
        return [
            'attachment_path' => $path,
            'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'attachment_mime_type' => (string) $file->getMimeType(),
            'attachment_size' => (int) $file->getSize(),
        ];
    }

    /** @param Collection<int, int> $requestedEmployeeIds */
    private function ensureAbsentRecords(int $month, int $year, Collection $requestedEmployeeIds): void
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $latestCompletedDay = now()->startOfDay()->subDay();
        if ($periodEnd->greaterThan($latestCompletedDay)) {
            $periodEnd = $latestCompletedDay;
        }
        if ($periodEnd->lessThan($periodStart)) {
            return;
        }

        $holidayDates = Holiday::query()
            ->where('active', true)
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();
        $employees = Employee::query()
            ->where('active', true)
            ->when($requestedEmployeeIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $requestedEmployeeIds))
            ->get(['id', 'employment_start_date']);
        $employeeIds = $employees->pluck('id');
        $existingRecords = AttendanceRecord::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('attendance_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get(['employee_id', 'attendance_date'])
            ->mapWithKeys(fn (AttendanceRecord $record) => [
                $this->scanKey($record->employee_id, $record->attendance_date->toDateString()) => true,
            ]);
        $rows = [];
        $now = now();

        foreach (CarbonPeriod::create($periodStart, $periodEnd) as $date) {
            if ($date->isWeekend() || $holidayDates->has($date->toDateString())) {
                continue;
            }

            foreach ($employees as $employee) {
                if ($employee->employment_start_date->greaterThan($date)) {
                    continue;
                }

                $attendanceDate = $date->toDateString();
                if ($existingRecords->has($this->scanKey($employee->id, $attendanceDate))) {
                    continue;
                }

                $rows[] = [
                    'employee_id' => $employee->id,
                    'attendance_date' => $attendanceDate,
                    'status' => 'absent',
                    'source' => 'system',
                    'incomplete' => false,
                    'note' => 'Dibuat otomatis setelah hari kerja berakhir.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            AttendanceRecord::query()->insertOrIgnore($chunk);
        }
    }
}
