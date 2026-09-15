<?php

namespace App\Http\Requests\Pengadaan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Persetujuan realisasi pengadaan oleh Kasubbag Umum dan TI.
 *
 * Namanya memakai `...Realization...` karena `ApproveProcurementRequestRequest`
 * sudah dipakai untuk persetujuan **usulan** — dua tahap berbeda dengan dua
 * pemutus berbeda (ADR 0016).
 *
 * Keterangan bersifat opsional di sini, berbeda dari penolakan: persetujuan
 * tidak menuntut penjelasan, penolakan menuntut. Lihat ADR 0030.
 */
class ApproveProcurementRealizationRequest extends FormRequest
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
            'review_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'review_note' => 'catatan persetujuan',
        ];
    }
}
