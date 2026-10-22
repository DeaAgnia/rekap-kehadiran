<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ExportAttendanceReportRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'category' => [
                'nullable',
                'string',
                'max:255',
                Rule::exists('categories', 'name')->where(fn ($query) => $query->where('active', true)),
            ],
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'month' => 'Bulan',
            'year' => 'Tahun',
            'category' => 'Kategori pegawai',
            'search' => 'Pencarian pegawai',
        ];
    }
}
