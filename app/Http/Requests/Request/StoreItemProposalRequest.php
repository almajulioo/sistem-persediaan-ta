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
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'item_name' => trim((string) $this->input('item_name')),
            'reason' => trim((string) $this->input('reason')),
        ]);
    }
}