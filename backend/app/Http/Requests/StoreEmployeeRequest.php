<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends ApiFormRequest
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
        $machineIdRules = ['required', 'string', 'max:50'];
        if ($this->input('machine_id') !== '-') {
            $machineIdRules[] = Rule::unique('employees', 'machine_id')->whereNull('deleted_at');
        }

        return [
            'nid_nip' => ['required', 'string', 'max:50', Rule::unique('employees', 'nid_nip')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'machine_id' => $machineIdRules,
            'employment_start_date' => ['required', 'date'],
            'attendance_type' => ['required', 'string', 'in:normal,mpp,stb'],
            'attendance_type_start_date' => [Rule::requiredIf($this->input('attendance_type') !== 'normal'), 'nullable', 'date'],
            'attendance_type_end_date' => [Rule::requiredIf($this->input('attendance_type') !== 'normal'), 'nullable', 'date', 'after_or_equal:attendance_type_start_date'],
            'active' => ['required', 'boolean'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $type = (string) ($this->input('attendance_type') ?: 'normal');
        $startDate = $this->input('attendance_type_start_date');
        $endDate = $this->input('attendance_type_end_date');
        if ($type === 'normal') {
            $startDate = null;
            $endDate = null;
        } elseif ($startDate && ! $endDate) {
            $start = Carbon::parse($startDate);
            $endDate = ($type === 'mpp' ? $start->addMonthsNoOverflow(6) : $start->addYears(4))->toDateString();
        }

        $this->merge([
            'nid_nip' => trim((string) $this->input('nid_nip')),
            'machine_id' => trim((string) $this->input('machine_id')),
            'attendance_type' => $type,
            'attendance_type_start_date' => $startDate,
            'attendance_type_end_date' => $endDate,
        ]);
    }

    public function attributes(): array
    {
        return [
            'nid_nip' => 'NIP/NID',
            'name' => 'Nama pegawai',
            'position' => 'Jabatan',
            'machine_id' => 'ID Mesin',
            'employment_start_date' => 'Tanggal mulai bekerja',
            'attendance_type' => 'Tipe kehadiran pegawai',
            'attendance_type_start_date' => 'Tanggal mulai tipe',
            'attendance_type_end_date' => 'Tanggal akhir tipe',
            'active' => 'Status aktif',
            'category_ids' => 'Kategori pegawai',
            'category_ids.*' => 'Kategori pegawai',
        ];
    }
}
