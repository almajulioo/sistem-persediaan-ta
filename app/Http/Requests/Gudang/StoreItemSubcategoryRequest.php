<?php

namespace App\Http\Requests\Gudang;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemSubcategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('kelola-gudang') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ItemSubcategoryFormRules::rules(Rule::unique('item_subcategories', 'subcategory_code'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subcategory_code' => mb_strtoupper(trim((string) $this->input('subcategory_code'))),
            'subcategory_name' => trim((string) $this->input('subcategory_name')),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ItemSubcategoryFormRules::attributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ItemSubcategoryFormRules::messages();
    }
}
