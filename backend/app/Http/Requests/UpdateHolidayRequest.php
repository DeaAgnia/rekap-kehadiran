<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateHolidayRequest extends ApiFormRequest
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
            'date' => ['required', 'date', Rule::unique('holidays', 'date')->ignore($this->route('holiday'))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:internal,national'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return (new StoreHolidayRequest)->attributes();
    }
}
