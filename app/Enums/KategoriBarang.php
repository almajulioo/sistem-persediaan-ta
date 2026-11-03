<?php

namespace App\Enums;

/**
 * Kategori barang persediaan, disimpan pada `item_subcategories.category` dan
 * disalin ke `items.category`. Hanya dua nilai tetap, jadi enum alih-alih
 * tabel. Lihat ADR 0043.
 */
enum KategoriBarang: string
{
    case ATK = 'ATK';
    case ARTK = 'ARTK';

    public function label(): string
    {
        return match ($this) {
            self::ATK => 'Alat Tulis Kantor',
            self::ARTK => 'Alat Rumah Tangga Kantor',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::ATK => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::ARTK => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $kategori) => $carry + [$kategori->value => "{$kategori->value} — {$kategori->label()}"],
            [],
        );
    }
}
