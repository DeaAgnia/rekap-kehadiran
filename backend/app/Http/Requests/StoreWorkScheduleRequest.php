<?php

namespace App\Http\Requests;

class StoreWorkScheduleRequest extends ApiFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i', 'after:work_start'],
            'break_start' => ['required', 'date_format:H:i', 'after:work_start', 'before:work_end'],
            'break_end' => ['required', 'date_format:H:i', 'after:break_start', 'before:work_end'],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'early_leave_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Nama aturan', 'work_start' => 'Jam masuk', 'work_end' => 'Jam pulang',
            'break_start' => 'Mulai istirahat', 'break_end' => 'Selesai istirahat',
            'late_tolerance_minutes' => 'Toleransi terlambat',
            'early_leave_tolerance_minutes' => 'Toleransi pulang cepat',
            'effective_from' => 'Mulai berlaku', 'effective_until' => 'Selesai berlaku', 'active' => 'Status aktif',
        ];
    }
}
