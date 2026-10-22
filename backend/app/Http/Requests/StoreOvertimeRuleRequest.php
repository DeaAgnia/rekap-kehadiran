<?php

namespace App\Http\Requests;

class StoreOvertimeRuleRequest extends ApiFormRequest
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
            'starts_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'max_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Nama aturan', 'starts_after_minutes' => 'Ambang lembur',
            'max_minutes' => 'Maksimal lembur', 'effective_from' => 'Mulai berlaku',
            'effective_until' => 'Selesai berlaku', 'active' => 'Status aktif',
        ];
    }
}
