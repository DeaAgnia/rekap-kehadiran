<?php

namespace App\Http\Requests;

class StoreHolidayRequest extends ApiFormRequest
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
            'date' => ['required', 'date', 'unique:holidays,date'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:internal,national'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['date' => 'Tanggal libur', 'name' => 'Nama hari libur', 'type' => 'Jenis libur', 'active' => 'Status aktif'];
    }
}
