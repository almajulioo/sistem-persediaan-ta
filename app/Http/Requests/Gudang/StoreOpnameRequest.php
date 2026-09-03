<?php

namespace App\Http\Requests\Gudang;

use App\Enums\PeriodeOpname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpnameRequest extends FormRequest
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
            'period_type' => ['required', Rule::enum(PeriodeOpname::class)],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'opname_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['notes' => trim((string) $this->input('notes')) ?: null]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period_type' => 'jenis periode',
            'period_year' => 'tahun',
            'opname_date' => 'tanggal pencacahan',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'period_type.enum' => 'Jenis periode yang dipilih tidak dikenali.',
            'period_year.min' => 'Tahun tidak masuk akal.',
            'period_year.max' => 'Tahun tidak masuk akal.',
            'opname_date.before_or_equal' => 'Tanggal pencacahan tidak boleh melebihi hari ini.',
            'notes.max' => 'Catatan maksimal :max karakter.',
        ];
    }
}
