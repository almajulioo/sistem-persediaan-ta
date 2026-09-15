<?php

namespace App\Http\Requests\Pengadaan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penolakan realisasi pengadaan oleh Kasubbag Umum dan TI.
 *
 * Keterangan **wajib**: penolakan tanpa alasan tidak dapat ditindaklanjuti
 * Pejabat Pengadaan, padahal justru menindaklanjuti yang diharapkan — realisasi
 * yang ditolak masih boleh diperbaiki lalu diajukan ulang (ADR 0030). Semangat
 * yang sama dengan keterangan wajib pada pembatalan (ADR 0024) dan alasan
 * selisih opname (ADR 0011).
 *
 * Alasannya sengaja berupa teks bebas, bukan enum baku seperti
 * `AlasanPembatalan`: daftar alasan penolakan yang sebenarnya dipakai di
 * lapangan belum pernah ditanyakan, dan membakukan tebakan justru memaksa
 * pemutusnya memilih alasan yang tidak tepat.
 */
class RejectProcurementRealizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('setujui-pengadaan') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'review_note' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'review_note' => 'alasan penolakan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'review_note.required' => 'Alasan penolakan wajib diisi agar Pejabat Pengadaan tahu apa yang harus diperbaiki.',
        ];
    }
}
