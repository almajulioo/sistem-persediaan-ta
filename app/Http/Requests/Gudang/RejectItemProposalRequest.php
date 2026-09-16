<?php

namespace App\Http\Requests\Gudang;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Menolak usulan barang baru (ADR 0032).
 *
 * Keterangan wajib, dan bukan formalitas: penolakan yang paling sering terjadi
 * adalah "barangnya sudah ada, pakai ATK-014" — tanpa keterangan itu, pengusul
 * tidak punya cara mengetahui barang mana yang dimaksud dan akan mengusulkan
 * hal yang sama lagi. Bebas, bukan enum, karena isinya menunjuk barang
 * tertentu. Sejajar `review_note` pada penolakan realisasi (ADR 0030).
 */
class RejectItemProposalRequest extends FormRequest
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
            'decision_note' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['decision_note' => 'alasan penolakan'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision_note.required' => 'Jelaskan alasan penolakannya. Bila barangnya sudah ada, sebutkan kode barangnya.',
        ];
    }
}
