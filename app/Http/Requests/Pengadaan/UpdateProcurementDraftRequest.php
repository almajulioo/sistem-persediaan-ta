<?php

namespace App\Http\Requests\Pengadaan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Menyimpan perubahan Pengelola Gudang pada draf usulan otomatis.
 *
 * Berbeda dari StoreProcurementRequestRequest: `items` boleh kosong —
 * mengirim nol baris adalah cara mengosongkan draf sampai terhapus otomatis
 * oleh ProcurementDraftController::update(). Lihat ADR 0027.
 *
 * Sumber tiap baris tidak diterima dari kiriman: baris yang sudah ada
 * mempertahankan sumbernya, baris baru selalu Manual, dan `note` hanya
 * disimpan untuk baris Manual (ADR 0044).
 */
class UpdateProcurementDraftRequest extends FormRequest
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
            // 'present', bukan 'required': array kosong sah.
            'items' => ['present', 'array'],
            'items.*.item_id' => ['required', 'string', 'distinct', Rule::exists('items', 'item_id')],
            'items.*.amount_request' => ['required', 'integer', 'min:1', 'max:999999'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => 'daftar barang',
            'items.*.item_id' => 'barang',
            'items.*.amount_request' => 'jumlah diminta',
            'items.*.note' => 'keterangan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.item_id.exists' => 'Barang yang dipilih tidak ditemukan.',
            'items.*.item_id.distinct' => 'Satu barang tidak boleh muncul dua kali dalam satu draf.',
            'items.*.amount_request.min' => 'Jumlah diminta minimal 1.',
        ];
    }
}
