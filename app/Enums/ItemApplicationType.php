<?php

namespace App\Enums;

use App\Support\Warna;

/**
 * Jenis satu baris permohonan, disimpan pada `item_application_details.type`.
 */
enum ItemApplicationType: string
{
    case TambahStok = 'tambah_stok';
    case BarangBaru = 'barang_baru';

    public function label(): string
    {
        return match ($this) {
            self::TambahStok => 'Tambah Stok',
            self::BarangBaru => 'Barang Baru',
        };
    }

    /**
     * Nama warna App\Support\Warna. Barang baru merah muda, sama dengan
     * ubin Usulan Barang Baru, dan tidak bentrok dengan kuning status Diajukan.
     */
    public function warna(): string
    {
        return match ($this) {
            self::TambahStok => 'brand',
            self::BarangBaru => 'pink',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TambahStok => 'package',
            self::BarangBaru => 'package-plus',
        };
    }

    public function badgeClass(): string
    {
        return Warna::lembut($this->warna());
    }
}
