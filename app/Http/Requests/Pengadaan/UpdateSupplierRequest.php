<?php

namespace App\Http\Requests\Pengadaan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('kelola-pengadaan') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_name' => [
                'required', 'string', 'max:255',
                // Penyedia yang sedang disunting dikecualikan agar menyimpan
                // tanpa mengubah nama tidak dianggap bentrok dengan dirinya.
                Rule::unique('suppliers', 'supplier_name')
                    ->ignore($this->route('supplier')?->supplier_id, 'supplier_id'),
            ],
        ] + SupplierFormRules::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'supplier_name' => trim((string) $this->input('supplier_name')),
            'address' => trim((string) $this->input('address')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return SupplierFormRules::attributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return SupplierFormRules::messages();
    }
}
