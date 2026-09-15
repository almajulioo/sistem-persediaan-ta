<?php

namespace App\Http\Requests\Request;

use App\Enums\SatuanBarang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'item_name' => ['required', 'string', 'max:255'],
            'unit' => ['required', Rule::enum(SatuanBarang::class)],
            'purpose_note' => ['required', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'item_name' => trim((string) $this->input('item_name')),
            'purpose_note' => trim((string) $this->input('purpose_note')),
        ]);
    }

    public function messages(): array
    {
        return [
            'purpose_note.required' => 'Jelaskan kebutuhan barangnya. Tanpa ini Pengelola Gudang tidak punya bahan untuk memutuskan.',
        ];
    }
}
