<?php

namespace App\Http\Requests\Pengadaan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Usulan yang diajukan langsung lewat formulir: seluruh barangnya bersumber
 * Manual, ditetapkan controller dan tidak diterima dari kiriman. Draf
 * pemantauan stok dan permohonan punya jalurnya sendiri (ADR 0027, ADR 0044).
 */
class StoreProcurementRequestRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
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
            'items.required' => 'Tambahkan minimal satu barang ke usulan.',
            'items.min' => 'Tambahkan minimal satu barang ke usulan.',
            'items.*.item_id.exists' => 'Barang yang dipilih tidak ditemukan.',
            'items.*.item_id.distinct' => 'Satu barang tidak boleh muncul dua kali dalam satu usulan.',
            'items.*.amount_request.min' => 'Jumlah diminta minimal 1.',
        ];
    }
}
