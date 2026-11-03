<?php

namespace App\Http\Requests\Gudang;

use App\Enums\KategoriBarang;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Aturan, label, dan pesan validasi yang dipakai bersama oleh formulir
 * tambah dan ubah sub kategori barang.
 */
class ItemSubcategoryFormRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(Unique $uniqueCode): array
    {
        return [
            // Kodenya mengikuti kodefikasi barang persediaan, jadi aturan
            // karakternya sama dengan kode barang.
            'subcategory_code' => ['required', 'string', 'max:50', 'regex:'.ItemFormRules::ITEM_CODE_REGEX, $uniqueCode],
            'subcategory_name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(KategoriBarang::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'subcategory_code' => 'kode sub kategori',
            'subcategory_name' => 'nama sub kategori',
            'category' => 'kategori',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'subcategory_code.regex' => 'Kode sub kategori hanya boleh berisi huruf, angka, titik, tanda hubung, dan garis miring.',
            'subcategory_code.unique' => 'Kode sub kategori sudah dipakai sub kategori lain.',
            'category.enum' => 'Kategori yang dipilih tidak dikenali.',
        ];
    }
}
