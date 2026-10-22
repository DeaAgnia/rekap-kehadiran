<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportFingerprintRequest;
use App\Models\AttendanceMachine;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\FingerprintScan;
use App\Models\ImportBatch;
use App\Services\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class FingerprintImportController extends Controller
{
    private const REQUIRED_HEADERS = ['ID', 'NAMA', 'TANGGAL', 'STATUS', 'STAT NAMA', 'EVALUASI', 'SUHU', 'JAM KERJA', 'MESIN', 'VERIFY'];

    public function index(): JsonResponse
    {
        return response()->json(['data' => ImportBatch::query()->latest()->limit(50)->get()]);
    }

    public function store(ImportFingerprintRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'rb');
        if (! $handle) {
            ActivityLogger::error('fingerprint.import_failed', 'File fingerprint tidak dapat dibaca.');

            return response()->json(['message' => 'File fingerprint tidak dapat dibaca.'], 422);
        }

        try {
            $header = fgetcsv($handle, escape: '');
            if (! is_array($header)) {
                return response()->json(['message' => 'Header CSV tidak ditemukan.'], 422);
            }

            $header = array_map(fn ($value) => strtoupper(trim((string) $value)), $header);
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            if ($header !== self::REQUIRED_HEADERS) {
                ActivityLogger::error('fingerprint.import_failed', 'Format header CSV tidak sesuai.', metadata: ['headers' => $header]);

                return response()->json([
                    'message' => 'Format header CSV tidak sesuai template mesin fingerprint.',
                    'errors' => ['file' => ['Kolom wajib: '.implode(', ', self::REQUIRED_HEADERS)]],
                ], 422);
            }

            try {
                $dateFormat = $this->detectDateFormat($handle, $header);
            } catch (InvalidArgumentException $exception) {
                ActivityLogger::error('fingerprint.import_failed', $exception->getMessage());

                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['file' => [$exception->getMessage()]],
                ], 422);
            }

            // Baca ulang file dari awal setelah format tanggal dideteksi dari seluruh baris.
            rewind($handle);
            fgetcsv($handle, escape: '');
            $attendanceMachines = AttendanceMachine::query()
                ->get()
                ->keyBy(fn (AttendanceMachine $machine) => mb_strtolower(trim($machine->serial_number)));

            $batch = ImportBatch::create(['filename' => $file->getClientOriginalName(), 'status' => 'processing']);
            $errors = [];
            $affectedDays = [];
            $rowCount = 0;
            $importedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            DB::beginTransaction();
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if ($values === [null] || count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }

                $rowCount++;
                if (count($values) !== count($header)) {
                    $errorCount++;
                    $errors[] = ['row' => $rowCount + 1, 'message' => 'Jumlah kolom tidak sesuai.'];

                    continue;
                }

                $row = array_combine($header, array_map(fn ($value) => trim((string) $value), $values));
                $machineId = trim($row['ID']);
                if ($machineId === '' || $machineId === '-') {
                    $errorCount++;
                    $errors[] = ['row' => $rowCount + 1, 'message' => 'ID Mesin tidak berisi identitas mesin yang dapat diimport.'];

                    continue;
                }

                $employee = Employee::query()->where('machine_id', $machineId)->first();
                if (! $employee) {
                    $errorCount++;
                    $errors[] = ['row' => $rowCount + 1, 'message' => "ID Mesin {$row['ID']} belum terdaftar."];

                    continue;
                }

                $deviceSerial = trim($row['MESIN']);
                $attendanceMachine = $attendanceMachines->get(mb_strtolower($deviceSerial));
                if ($deviceSerial === '' || ! $attendanceMachine) {
                    $errorCount++;
                    $errors[] = ['row' => $rowCount + 1, 'message' => "Kode SN mesin {$row['MESIN']} belum terdaftar di Data Master Mesin Absensi."];

                    continue;
                }
                if (! $attendanceMachine->active) {
                    $errorCount++;
                    $errors[] = ['row' => $rowCount + 1, 'message' => "Mesin {$attendanceMachine->name} berstatus tidak aktif."];

                    continue;
                }

                $scannedAt = $this->parseDateStrictly($row['TANGGAL'], $dateFormat);
                if (! $scannedAt) {
                    $errorCount++;
                    $errors[] = ['row' => $rowCount + 1, 'message' => "Tanggal {$row['TANGGAL']} tidak valid."];

                    continue;
                }

                $scan = FingerprintScan::firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'scanned_at' => $scannedAt,
                        'device_serial' => $attendanceMachine->serial_number,
                    ],
                    [
                        'verify_code' => $row['VERIFY'] ?: null,
                        'import_batch_id' => $batch->id,
                        'raw_payload' => $row,
                    ]
                );

                if (! $scan->wasRecentlyCreated) {
                    $skippedCount++;

                    continue;
                }

                $importedCount++;
                $date = $scannedAt->toDateString();
                $affectedDays["{$employee->id}|{$date}"] = [$employee->id, $date];
            }

            foreach ($affectedDays as [$employeeId, $date]) {
                $scans = FingerprintScan::query()
                    ->where('employee_id', $employeeId)
                    ->whereDate('scanned_at', $date)
                    ->orderBy('scanned_at')
                    ->get();
                $existing = AttendanceRecord::query()
                    ->where('employee_id', $employeeId)
                    ->whereDate('attendance_date', $date)
                    ->first();
                $hasCompleteFingerprint = $scans->count() > 1;
                $manualStatus = $existing?->manual_status;
                $effectiveStatus = $hasCompleteFingerprint || ! $manualStatus ? 'present' : $manualStatus;

                AttendanceRecord::query()->updateOrCreate(
                    ['employee_id' => $employeeId, 'attendance_date' => $date],
                    [
                        'status' => $effectiveStatus,
                        'manual_status' => $manualStatus,
                        'check_in' => $scans->first()->scanned_at->format('H:i:s'),
                        'check_out' => $hasCompleteFingerprint ? $scans->last()->scanned_at->format('H:i:s') : null,
                        'source' => $manualStatus ? 'mixed' : 'fingerprint',
                        'incomplete' => ! $hasCompleteFingerprint,
                        'note' => $hasCompleteFingerprint ? null : 'Data tidak lengkap/lupa absen pulang.',
                        'import_batch_id' => $batch->id,
                    ]
                );
            }

            $batch->update([
                'row_count' => $rowCount,
                'imported_count' => $importedCount,
                'skipped_count' => $skippedCount,
                'error_count' => $errorCount,
                'status' => $errorCount > 0 ? 'partial' : 'completed',
                'errors' => array_slice($errors, 0, 100),
            ]);
            DB::commit();

            ActivityLogger::success('fingerprint.imported', 'File fingerprint berhasil diproses.', $batch, $batch->only(['row_count', 'imported_count', 'skipped_count', 'error_count']));

            return response()->json(['message' => 'File fingerprint berhasil diproses.', 'data' => $batch->fresh()], 201);
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            ActivityLogger::error('fingerprint.import_failed', 'Import fingerprint gagal.', metadata: ['error' => $exception->getMessage()]);
            throw $exception;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Mesin fingerprint dapat mengekspor DD/MM/YYYY atau MM/DD/YYYY.
     * Nilai di atas 12 menjadi bukti posisi hari sehingga format dapat dipilih
     * untuk seluruh file. File yang seluruh tanggalnya ambigu mempertahankan
     * format lama DD/MM/YYYY demi kompatibilitas.
     *
     * @param  resource  $handle
     * @param  array<int, string>  $header
     */
    private function detectDateFormat($handle, array $header): string
    {
        $dateIndex = array_search('TANGGAL', $header, true);
        $hasDayMonthEvidence = false;
        $hasMonthDayEvidence = false;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($dateIndex === false || ! array_key_exists($dateIndex, $values)) {
                continue;
            }

            $value = trim((string) $values[$dateIndex]);
            if (! preg_match('/^(\d{1,2})\/(\d{1,2})\/\d{4}\s+\d{2}:\d{2}:\d{2}$/', $value, $matches)) {
                continue;
            }

            $firstPart = (int) $matches[1];
            $secondPart = (int) $matches[2];
            $hasDayMonthEvidence = $hasDayMonthEvidence || $firstPart > 12;
            $hasMonthDayEvidence = $hasMonthDayEvidence || $secondPart > 12;
        }

        if ($hasDayMonthEvidence && $hasMonthDayEvidence) {
            throw new InvalidArgumentException('Format tanggal dalam file tidak konsisten. Gunakan satu format tanggal untuk seluruh file.');
        }

        return $hasMonthDayEvidence ? 'm/d/Y H:i:s' : 'd/m/Y H:i:s';
    }

    /** Validasi ketat mencegah tanggal seperti bulan ke-31 dinormalisasi oleh Carbon. */
    private function parseDateStrictly(string $value, string $format): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat($format, $value, 'Asia/Jakarta');

            return $date->format($format) === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }
}
