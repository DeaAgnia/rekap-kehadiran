<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateAttendanceMachineRequest extends StoreAttendanceMachineRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'serial_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('attendance_machines', 'serial_number')->ignore($this->route('attendance_machine')),
            ],
            'location' => ['required', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ];
    }
}
