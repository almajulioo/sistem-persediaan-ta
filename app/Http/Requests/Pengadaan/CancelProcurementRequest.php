<?php

namespace App\Http\Requests\Pengadaan;

use App\Enums\AlasanPembatalan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pembatalan realisasi pengadaan oleh Pejabat Pengadaan.
 *
 * Keterangan **wajib** diisi, tidak peduli alasan mana yang dipilih. Alasan
 * baku membuat pembatalan dapat direkap, tetapi hanya keterangannya yang dapat
 * menjawab pertanyaan yang sebenarnya diajukan pemeriksa: apa yang terjadi
 * pada pengadaan ini — pola yang sama dengan alasan selisih opname (ADR 0011).
 *
 * Lihat ADR 0024.
 */
class CancelProcurementRequest extends FormRequest
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
            'cancel_reason' => ['required', Rule::enum(AlasanPembatalan::class)],
            'cancel_note' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cancel_reason' => 'alasan pembatalan',
            'cancel_note' => 'keterangan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancel_reason.required' => 'Pilih alasan pembatalan terlebih dahulu.',
            'cancel_note.required' => 'Keterangan wajib diisi supaya pembatalan ini dapat dipertanggungjawabkan.',
        ];
    }

    public function alasan(): AlasanPembatalan
    {
        return AlasanPembatalan::from($this->string('cancel_reason')->value());
    }
}
