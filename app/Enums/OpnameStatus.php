<?php

namespace App\Enums;

/**
 * Status dokumen stok opname.
 *
 * Draf masih dapat diubah dan dihapus. Setelah diposting menjadi Selesai,
 * dokumen bersifat final: penyesuaian sudah tercatat di buku mutasi yang
 * immutable, sehingga opname pun tidak boleh berubah lagi.
 */
enum OpnameStatus: string
{
    case Draf = 'draf';
    case Selesai = 'selesai';

    public function label(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Selesai => 'Selesai',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draf => 'Pencacahan masih berjalan, penyesuaian belum diterapkan.',
            self::Selesai => 'Penyesuaian sudah diterapkan dan tercatat di riwayat mutasi.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draf => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Selesai => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown penyaring.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $status) => $carry + [$status->value => $status->label()],
            [],
        );
    }
}
