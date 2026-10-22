<?php

namespace App\Http\Requests;

class StoreCategoryRequest extends ApiFormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
            'group' => ['required', 'string', 'in:Tendik,Dosen,Pimpinan'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'Nama kategori', 'group' => 'Kelompok', 'active' => 'Status aktif'];
    }
}
