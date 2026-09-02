<?php

namespace App\Enums;

/**
 * Jenis mutasi stok, disimpan pada kolom `stock_mutations.mutation_type`.
 *
 * Arah mutasi ditentukan oleh jenisnya, bukan oleh tanda pada nilai yang
 * dikirim pemanggil. Lihat App\Support\StockLedger.
 */
enum MutationType: string
{
    case SaldoAwal = 'saldo_awal';
    case Masuk = 'masuk';
    case Keluar = 'keluar';
    case Penyesuaian = 'penyesuaian';

    public function label(): string
    {
        return match ($this) {
            self::SaldoAwal => 'Saldo Awal',
            self::Masuk => 'Barang Masuk',
            self::Keluar => 'Barang Keluar',
            self::Penyesuaian => 'Penyesuaian',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SaldoAwal => 'Stok pembuka saat barang pertama kali didaftarkan.',
            self::Masuk => 'Penerimaan barang dari pengadaan.',
            self::Keluar => 'Penyerahan barang atas permintaan unit kerja.',
            self::Penyesuaian => 'Koreksi stok hasil stock opname.',
        };
    }

    /**
     * Menambah stok (+1), mengurangi stok (-1), atau mengikuti tanda nilai
     * yang diberikan (0) untuk penyesuaian yang bisa naik maupun turun.
     */
    public function direction(): int
    {
        return match ($this) {
            self::SaldoAwal, self::Masuk => 1,
            self::Keluar => -1,
            self::Penyesuaian => 0,
        };
    }

    /**
     * Kelas badge TailAdmin untuk menampilkan jenis mutasi di UI.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::SaldoAwal => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::Masuk => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Keluar => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Penyesuaian => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown form.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $type) => $carry + [$type->value => $type->label()],
            [],
        );
    }
}
