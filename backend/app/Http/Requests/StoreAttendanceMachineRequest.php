<?php

namespace App\Http\Requests;

class StoreAttendanceMachineRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'serial_number' => ['required', 'string', 'max:100', 'unique:attendance_machines,serial_number'],
            'location' => ['required', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Nama mesin',
            'serial_number' => 'Kode SN mesin',
            'location' => 'Lokasi mesin',
            'active' => 'Status mesin',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'serial_number' => trim((string) $this->input('serial_number')),
            'location' => trim((string) $this->input('location')),
        ]);
    }
}
