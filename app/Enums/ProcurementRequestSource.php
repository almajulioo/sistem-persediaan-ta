<?php

namespace App\Enums;

/**
 * Asal-usul usulan pengadaan, disimpan pada `procurement_requests.source`.
 *
 * Dikonfirmasi narasumber lapangan (Kasubbag Umum dan TI): usulan pengadaan
 * lahir dari tiga jalur berbeda, bukan satu jenis "manual" generik. Hanya
 * `DariPermintaan` yang punya dokumen asal untuk ditunjuk — lihat
 * `reference_type`/`reference_id` pada `ProcurementRequest`.
 */
enum ProcurementRequestSource: string
{
    case DariPermintaan = 'dari_permintaan';
    case PemantauanStok = 'pemantauan_stok';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::DariPermintaan => 'Dari Permintaan',
            self::PemantauanStok => 'Pemantauan Stok',
            self::Manual => 'Manual',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DariPermintaan => 'Permintaan unit kerja tidak dapat dipenuhi karena stok kurang atau habis.',
            self::PemantauanStok => 'Muncul dari notifikasi stok habis atau menipis.',
            self::Manual => 'Diajukan langsung oleh Pengelola Gudang tanpa pemicu otomatis.',
        };
    }

    /**
     * Apakah sumber ini punya dokumen asal untuk ditunjuk lewat
     * `reference_type`/`reference_id`.
     */
    public function butuhReferensi(): bool
    {
        return $this === self::DariPermintaan;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::DariPermintaan => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::PemantauanStok => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Manual => 'bg-gray-100 text-gray-600 dark:bg-gray-500/15 dark:text-gray-400',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown form.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $source) => $carry + [$source->value => $source->label()],
            [],
        );
    }
}
