<?php

namespace App\Http\Requests\Pengadaan;

use App\Enums\ProcurementRequestSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // `dari_permintaan` belum bisa dipilih lewat formulir ini: sumber itu
            // butuh referensi ke `requests` milik sub sistem Permintaan, dan
            // integrasinya belum dibangun. Lihat ADR 0015.
            //
            // `pemantauan_stok` juga tidak lagi diterima di sini sejak ADR 0027:
            // jalur itu sekarang selalu lewat draf otomatis
            // (ProcurementDraftController), bukan formulir manual ini.
            'source' => [
                'required',
                Rule::in([ProcurementRequestSource::Manual->value]),
            ],
            'items' => ['required', 'array', 'min:1'],
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
            'items.required' => 'Tambahkan minimal satu barang ke usulan.',
            'items.min' => 'Tambahkan minimal satu barang ke usulan.',
            'items.*.item_id.exists' => 'Barang yang dipilih tidak ditemukan.',
            'items.*.item_id.distinct' => 'Satu barang tidak boleh muncul dua kali dalam satu usulan.',
            'items.*.amount_request.min' => 'Jumlah diminta minimal 1.',
        ];
    }
}
