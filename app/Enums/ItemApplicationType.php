<?php

namespace App\Enums;

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

    public function badgeClass(): string
    {
        return match ($this) {
            self::TambahStok => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::BarangBaru => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
        };
    }
}
