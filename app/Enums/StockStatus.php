<?php

namespace App\Enums;

/**
 * Status kecukupan stok sebuah barang, dihitung dari stock_current
 * terhadap stock_min dan stock_max. Bukan kolom database.
 */
enum StockStatus: string
{
    case Habis = 'habis';
    case Menipis = 'menipis';
    case Aman = 'aman';
    case Berlebih = 'berlebih';

    public function label(): string
    {
        return match ($this) {
            self::Habis => 'Habis',
            self::Menipis => 'Menipis',
            self::Aman => 'Aman',
            self::Berlebih => 'Berlebih',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Habis => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Menipis => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Aman => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Berlebih => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
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
