<?php

namespace App\Http\Requests;

use App\Services\ActivityLogger;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        ActivityLogger::error(
            'validation.failed',
            'Validasi form gagal.',
            metadata: ['errors' => $validator->errors()->toArray(), 'path' => $this->path()]
        );

        parent::failedValidation($validator);
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'unique' => ':attribute sudah digunakan.',
            'exists' => ':attribute yang dipilih tidak ditemukan.',
            'date' => ':attribute harus berupa tanggal yang valid.',
            'date_format' => ':attribute tidak sesuai format yang diminta.',
            'after' => ':attribute harus setelah :date.',
            'after_or_equal' => ':attribute harus sama atau setelah :date.',
            'before' => ':attribute harus sebelum :date.',
            'in' => ':attribute tidak valid.',
            'integer' => ':attribute harus berupa angka bulat.',
            'min' => ':attribute minimal :min.',
            'max' => ':attribute maksimal :max.',
            'array' => ':attribute harus berupa daftar.',
        ];
    }
}
