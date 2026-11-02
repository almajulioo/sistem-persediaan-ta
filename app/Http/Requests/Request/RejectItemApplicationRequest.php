<?php

namespace App\Http\Requests\Request;

use Illuminate\Foundation\Http\FormRequest;

class RejectItemApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('kelola-permintaan') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision_note' => ['required', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['decision_note' => trim((string) $this->input('decision_note'))]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision_note.required' => 'Alasan penolakan wajib diisi supaya pemohon tahu apa yang perlu diperbaiki.',
        ];
    }
}
