<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;

class AttendanceStatusResolver
{
    public function effectiveStatus(AttendanceRecord $record): string
    {
        return $this->resolve(
            $record->status,
            $record->employee,
            $record->attendance_date
        );
    }

    public function resolve(string $status, ?Employee $employee, Carbon|string $date): string
    {
        if ($status !== 'absent' || ! $employee) {
            return $status;
        }

        if ($this->isLeader($employee)) {
            return 'present';
        }

        return $this->employeeTypeForDate($employee, $date) ?? $status;
    }

    private function isLeader(Employee $employee): bool
    {
        if ($employee->relationLoaded('categories')) {
            return $employee->categories->contains(
                fn ($category): bool => $category->active && $category->group === 'Pimpinan'
            );
        }

        return $employee->categories()
            ->where('active', true)
            ->where('group', 'Pimpinan')
            ->exists();
    }

    public function employeeTypeForDate(Employee $employee, Carbon|string $date): ?string
    {
        if (! in_array($employee->attendance_type, ['mpp', 'stb'], true)
            || ! $employee->attendance_type_start_date
            || ! $employee->attendance_type_end_date) {
            return null;
        }

        $targetDate = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();

        return $targetDate->betweenIncluded(
            $employee->attendance_type_start_date->copy()->startOfDay(),
            $employee->attendance_type_end_date->copy()->startOfDay()
        ) ? $employee->attendance_type : null;
    }
}
