<?php

namespace App\Enums;

/**
 * Peran pengguna sistem persediaan.
 *
 * Nilai enum disimpan sebagai string pada kolom `users.role`.
 * Urutan case menyatakan hierarki, dari yang tertinggi ke terendah.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case PengelolaGudang = 'pengelola_gudang';
    case PejabatPengadaan = 'pejabat_pengadaan';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::PengelolaGudang => 'Pengelola Gudang',
            self::PejabatPengadaan => 'Pejabat Pengadaan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Akses penuh: manajemen pengguna, master data, dan seluruh modul.',
            self::PengelolaGudang => 'Mengelola stok, permintaan barang, mutasi, dan stock opname.',
            self::PejabatPengadaan => 'Mengelola usulan dan realisasi pengadaan barang.',
        };
    }

    /**
     * Kelas badge TailAdmin untuk menampilkan peran di UI.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Admin => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::PengelolaGudang => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::PejabatPengadaan => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown form.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $role) => $carry + [$role->value => $role->label()],
            [],
        );
    }
}
