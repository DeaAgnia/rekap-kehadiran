<?php

namespace App\Http\Requests;

use App\Models\WorkSchedule;
use Illuminate\Validation\Validator;

class UpdateAttendanceSettingRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i', 'after:work_start'],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'overtime_starts_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'overtime_max_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $schedule = WorkSchedule::query()->where('active', true)->latest('effective_from')->first();
            if (! $schedule) {
                return;
            }

            $workStart = (string) $this->input('work_start');
            $workEnd = (string) $this->input('work_end');
            if ($workStart >= substr((string) $schedule->break_start, 0, 5)) {
                $validator->errors()->add('work_start', 'Jam masuk harus sebelum jam istirahat yang sedang aktif.');
            }
            if ($workEnd <= substr((string) $schedule->break_end, 0, 5)) {
                $validator->errors()->add('work_end', 'Jam pulang harus setelah jam istirahat yang sedang aktif.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'work_start' => 'Jam masuk',
            'work_end' => 'Jam pulang',
            'late_tolerance_minutes' => 'Toleransi keterlambatan',
            'overtime_starts_after_minutes' => 'Ambang lembur',
            'overtime_max_minutes' => 'Maksimal lembur',
        ];
    }
}
