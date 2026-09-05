<?php

namespace App\Http\Requests\Pengadaan;

/**
 * Aturan, label, dan pesan validasi yang dipakai bersama oleh formulir tambah
 * dan ubah penyedia.
 */
class SupplierFormRules
{
    /**
     * NPWP: angka, titik, dan tanda hubung. Sengaja longgar — format resmi
     * yang dipakai BPK Lampung belum dikonfirmasi, dan validasi yang terlalu
     * ketat hanya akan menolak data yang sebenarnya benar.
     */
    public const NPWP_REGEX = '/^[0-9.\-]+$/';

    /**
     * Nomor telepon: angka, spasi, tanda hubung, tanda kurung, dan awalan +.
     */
    public const PHONE_REGEX = '/^[0-9+()\-\s]+$/';

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'address' => ['required', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:'.self::PHONE_REGEX],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:25', 'regex:'.self::NPWP_REGEX],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'supplier_name' => 'nama penyedia',
            'address' => 'alamat',
            'phone' => 'nomor telepon',
            'email' => 'surel',
            'contact_person' => 'narahubung',
            'npwp' => 'NPWP',
            'is_active' => 'status penyedia',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'max' => ':attribute terlalu panjang, maksimal :max karakter.',
            'supplier_name.unique' => 'Nama penyedia sudah terdaftar.',
            'address.required' => 'Alamat wajib diisi karena dicantumkan pada BAST.',
            'email.email' => 'Surel tidak berformat alamat surel yang sah.',
            'phone.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, tanda hubung, kurung, dan tanda plus.',
            'npwp.regex' => 'NPWP hanya boleh berisi angka, titik, dan tanda hubung.',
        ];
    }
}
