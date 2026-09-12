<?php

namespace App\Http\Requests\Pengadaan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Menyimpan perubahan Pengelola Gudang pada draf usulan otomatis.
 *
 * Berbeda dari StoreProcurementRequestRequest: tidak ada kolom `source`
 * (selalu PemantauanStok), dan `items` boleh kosong — mengirim nol baris
 * adalah cara mengosongkan draf sampai terhapus otomatis oleh
 * ProcurementDraftController::update(). Lihat ADR 0027.
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
