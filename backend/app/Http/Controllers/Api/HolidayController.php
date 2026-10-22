<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHolidayRequest;
use App\Http\Requests\UpdateHolidayRequest;
use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $holidays = Holiday::query()
            ->when($request->integer('year'), fn ($query, $year) => $query->whereYear('date', $year))
            ->orderBy('date')
            ->get();

        return response()->json(['data' => $holidays]);
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $holiday = Holiday::create($request->validated());
        $this->removeSystemAbsences($holiday);
        ActivityLogger::success('holiday.created', 'Hari libur berhasil ditambahkan.', $holiday);

        return response()->json(['message' => 'Hari libur berhasil ditambahkan.', 'data' => $holiday], 201);
    }

    public function show(Holiday $holiday): JsonResponse
    {
        return response()->json(['data' => $holiday]);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $holiday->update($request->validated());
        $this->removeSystemAbsences($holiday);
        ActivityLogger::success('holiday.updated', 'Hari libur berhasil diperbarui.', $holiday);

        return response()->json(['message' => 'Hari libur berhasil diperbarui.', 'data' => $holiday->fresh()]);
    }

    public function updateStatus(Request $request, Holiday $holiday): JsonResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $holiday->update($validated);
        $this->removeSystemAbsences($holiday);
        ActivityLogger::success('holiday.status_updated', 'Status hari libur berhasil diperbarui.', $holiday, $validated);

        return response()->json(['message' => 'Status hari libur berhasil diperbarui.', 'data' => $holiday->fresh()]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();
        ActivityLogger::success('holiday.deleted', 'Hari libur berhasil dihapus.', $holiday);

        return response()->json(['message' => 'Hari libur berhasil dihapus.']);
    }

    /**
     * Hari libur aktif tidak boleh tetap memiliki TK yang dibuat otomatis.
     * Data manual dan fingerprint dipertahankan sebagai bukti aktivitas.
     */
    private function removeSystemAbsences(Holiday $holiday): void
    {
        if (! $holiday->active) {
            return;
        }

        AttendanceRecord::query()
            ->whereDate('attendance_date', $holiday->date)
            ->where('source', 'system')
            ->where('status', 'absent')
            ->delete();
    }
}
