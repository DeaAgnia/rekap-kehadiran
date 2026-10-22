<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends ApiFormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($this->route('category'))],
            'group' => ['required', 'string', 'in:Tendik,Dosen,Pimpinan'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return (new StoreCategoryRequest)->attributes();
    }
}
