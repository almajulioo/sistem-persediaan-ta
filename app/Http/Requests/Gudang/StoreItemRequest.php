<?php

namespace App\Http\Requests\Gudang;

use App\Enums\SatuanBarang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
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
        return [
            'item_code' => ['required', 'string', 'max:50', 'regex:'.ItemFormRules::ITEM_CODE_REGEX, Rule::unique('items', 'item_code')],
            'item_name' => ['required', 'string', 'max:255'],
            'unit' => ['required', Rule::enum(SatuanBarang::class)],
            'stock_min' => ['required', 'integer', 'min:0', 'max:999999'],
            'stock_max' => ['required', 'integer', 'min:0', 'max:999999', 'gte:stock_min'],
            // Hanya ada saat menambah; tercatat sebagai mutasi "Saldo Awal".
            'stock_awal' => ['required', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'item_code' => mb_strtoupper(trim((string) $this->input('item_code'))),
            'item_name' => trim((string) $this->input('item_name')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ItemFormRules::attributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ItemFormRules::messages();
    }
}
