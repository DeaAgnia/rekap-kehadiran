<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAttendanceSettingRequest;
use App\Models\OvertimeRule;
use App\Models\WorkSchedule;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AttendanceSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->currentSettings()]);
    }

    public function update(UpdateAttendanceSettingRequest $request): JsonResponse
    {
        [$schedule, $overtimeRule] = DB::transaction(function () use ($request): array {
            $schedule = WorkSchedule::query()->where('active', true)->latest('effective_from')->lockForUpdate()->firstOrFail();
            $overtimeRule = OvertimeRule::query()->where('active', true)->latest('effective_from')->lockForUpdate()->firstOrFail();
            $validated = $request->validated();

            $schedule->update([
                'work_start' => $validated['work_start'],
                'work_end' => $validated['work_end'],
                'late_tolerance_minutes' => $validated['late_tolerance_minutes'],
            ]);
            $overtimeRule->update([
                'starts_after_minutes' => $validated['overtime_starts_after_minutes'],
                'max_minutes' => $validated['overtime_max_minutes'],
            ]);

            return [$schedule, $overtimeRule];
        });

        ActivityLogger::success(
            'attendance_setting.updated',
            'Pengaturan kehadiran berhasil diperbarui.',
            $schedule,
            ['overtime_rule_id' => $overtimeRule->id]
        );

        return response()->json([
            'message' => 'Pengaturan kehadiran berhasil diperbarui.',
            'data' => $this->currentSettings(),
        ]);
    }

    private function currentSettings(): array
    {
        $schedule = WorkSchedule::query()->where('active', true)->latest('effective_from')->firstOrFail();
        $overtimeRule = OvertimeRule::query()->where('active', true)->latest('effective_from')->firstOrFail();

        return [
            'work_schedule_id' => $schedule->id,
            'overtime_rule_id' => $overtimeRule->id,
            'work_start' => substr((string) $schedule->work_start, 0, 5),
            'work_end' => substr((string) $schedule->work_end, 0, 5),
            'late_tolerance_minutes' => $schedule->late_tolerance_minutes,
            'overtime_starts_after_minutes' => $overtimeRule->starts_after_minutes,
            'overtime_max_minutes' => $overtimeRule->max_minutes,
            'effective_from' => $schedule->effective_from->toDateString(),
        ];
    }
}
