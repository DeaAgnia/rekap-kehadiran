<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Models\OvertimeRule;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceCalculator
{
    public function evaluate(AttendanceRecord $record): array
    {
        $date = $record->attendance_date->toDateString();
        $isHoliday = Holiday::query()->whereDate('date', $date)->where('active', true)->exists();
        $schedule = $this->scheduleFor($date);
        $overtimeRule = $this->overtimeRuleFor($date);

        return $this->calculate($record, $isHoliday, $schedule, $overtimeRule);
    }

    /**
     * Menghitung metrik satu periode dengan tiga query referensi saja.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @return Collection<int, array<string, bool|int|null>>
     */
    public function evaluateMany(Collection $records): Collection
    {
        if ($records->isEmpty()) {
            return collect();
        }

        $startDate = $records->min(fn (AttendanceRecord $record) => $record->attendance_date->toDateString());
        $endDate = $records->max(fn (AttendanceRecord $record) => $record->attendance_date->toDateString());
        $holidays = Holiday::query()
            ->where('active', true)
            ->whereBetween('date', [$startDate, $endDate])
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();
        $schedules = WorkSchedule::query()
            ->where('active', true)
            ->whereDate('effective_from', '<=', $endDate)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $startDate))
            ->latest('effective_from')
            ->get();
        $overtimeRules = OvertimeRule::query()
            ->where('active', true)
            ->whereDate('effective_from', '<=', $endDate)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $startDate))
            ->latest('effective_from')
            ->get();

        return $records->mapWithKeys(function (AttendanceRecord $record) use ($holidays, $schedules, $overtimeRules): array {
            $date = $record->attendance_date->toDateString();

            return [$record->id => $this->calculate(
                $record,
                $holidays->has($date),
                $this->referenceForDate($schedules, $date),
                $this->referenceForDate($overtimeRules, $date),
            )];
        });
    }

    private function calculate(
        AttendanceRecord $record,
        bool $isHoliday,
        ?WorkSchedule $schedule,
        ?OvertimeRule $overtimeRule,
    ): array {
        $date = $record->attendance_date->toDateString();
        $isWeekend = $record->attendance_date->isWeekend();
        $result = [
            'total_work_minutes' => null,
            'late_minutes' => null,
            'early_leave_minutes' => null,
            'overtime_minutes' => null,
            'is_weekend' => $isWeekend,
            'is_holiday' => $isHoliday,
        ];

        if (! $record->check_in || ! $record->check_out || ! $schedule) {
            return $result;
        }

        $checkIn = Carbon::parse("{$date} {$record->check_in}");
        $checkOut = Carbon::parse("{$date} {$record->check_out}");
        $workStart = Carbon::parse("{$date} {$schedule->work_start}");
        $workEnd = Carbon::parse("{$date} {$schedule->work_end}");
        $breakStart = Carbon::parse("{$date} {$schedule->break_start}");
        $breakEnd = Carbon::parse("{$date} {$schedule->break_end}");

        $workedMinutes = max(0, $checkIn->diffInMinutes($checkOut, false));
        $overlapStart = $checkIn->greaterThan($breakStart) ? $checkIn : $breakStart;
        $overlapEnd = $checkOut->lessThan($breakEnd) ? $checkOut : $breakEnd;
        $breakMinutes = $overlapEnd->greaterThan($overlapStart)
            ? $overlapStart->diffInMinutes($overlapEnd)
            : 0;

        $result['total_work_minutes'] = max(0, $workedMinutes - $breakMinutes);
        $result['late_minutes'] = max(
            0,
            $workStart->copy()->addMinutes($schedule->late_tolerance_minutes)->diffInMinutes($checkIn, false)
        );
        $result['early_leave_minutes'] = max(
            0,
            $checkOut->diffInMinutes(
                $workEnd->copy()->subMinutes($schedule->early_leave_tolerance_minutes),
                false
            )
        );

        if (! $isWeekend && ! $isHoliday && $overtimeRule) {
            $overtimeStartsAt = $workEnd->copy()->addMinutes($overtimeRule->starts_after_minutes);
            $result['overtime_minutes'] = $checkOut->greaterThan($overtimeStartsAt)
                ? min($overtimeStartsAt->diffInMinutes($checkOut), $overtimeRule->max_minutes)
                : 0;
        }

        return $result;
    }

    /**
     * @template TModel of WorkSchedule|OvertimeRule
     *
     * @param  Collection<int, TModel>  $references
     * @return TModel|null
     */
    private function referenceForDate(Collection $references, string $date): WorkSchedule|OvertimeRule|null
    {
        $targetDate = Carbon::parse($date)->startOfDay();

        return $references->first(function (WorkSchedule|OvertimeRule $reference) use ($targetDate): bool {
            $startsBeforeOrOnDate = $reference->effective_from->copy()->startOfDay()->lessThanOrEqualTo($targetDate);
            $endsAfterOrOnDate = ! $reference->effective_until
                || $reference->effective_until->copy()->startOfDay()->greaterThanOrEqualTo($targetDate);

            return $startsBeforeOrOnDate && $endsAfterOrOnDate;
        });
    }

    private function scheduleFor(string $date): ?WorkSchedule
    {
        return WorkSchedule::query()
            ->where('active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->latest('effective_from')
            ->first();
    }

    private function overtimeRuleFor(string $date): ?OvertimeRule
    {
        return OvertimeRule::query()
            ->where('active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->latest('effective_from')
            ->first();
    }
}
