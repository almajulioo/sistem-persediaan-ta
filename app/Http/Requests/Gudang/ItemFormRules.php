<?php

namespace App\Http\Requests\Gudang;

/**
 * Aturan, label, dan pesan validasi yang dipakai bersama oleh formulir
 * tambah dan ubah barang.
 */
class ItemFormRules
{
    /**
     * Kode barang: huruf besar, angka, titik, tanda hubung, dan garis miring.
     * Cukup longgar untuk mengikuti kodefikasi barang persediaan yang berlaku.
     */
    public const ITEM_CODE_REGEX = '/^[A-Z0-9.\-\/]+$/';

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'item_code' => 'kode barang',
            'item_name' => 'nama barang',
            'unit' => 'satuan',
            'stock_min' => 'stok minimum',
            'stock_max' => 'stok maksimum',
            'stock_awal' => 'stok awal',
            'is_active' => 'status barang',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'integer' => ':attribute harus berupa angka bulat.',
            'min' => ':attribute tidak boleh kurang dari :min.',
            'item_code.regex' => 'Kode barang hanya boleh berisi huruf, angka, titik, tanda hubung, dan garis miring.',
            'item_code.unique' => 'Kode barang sudah dipakai barang lain.',
            'unit.enum' => 'Satuan yang dipilih tidak dikenali.',
            'stock_max.gte' => 'Stok maksimum tidak boleh kurang dari stok minimum.',
        ];
    }
}
