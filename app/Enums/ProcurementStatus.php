<?php

namespace App\Enums;

/**
 * Status realisasi pengadaan, disimpan pada `procurements.status`.
 *
 * Sebuah `Procurement` baru lahir begitu usulannya Disetujui, jadi alur ini
 * tidak mengulang tahap persetujuan — lihat `ProcurementRequestStatus`. Case
 * `MenungguPenerimaan` sengaja memakai istilah yang sama persis dengan
 * ADR 0008: realisasi berhenti di situ sampai Pengelola Gudang mengonfirmasi
 * barang fisik diterima, dan baru saat itu stok bertambah.
 */
enum ProcurementStatus: string
{
    case MenungguPenerimaan = 'menunggu_penerimaan';
    case Diterima = 'diterima';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::MenungguPenerimaan => 'Menunggu Penerimaan',
            self::Diterima => 'Diterima',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MenungguPenerimaan => 'Realisasi berjalan, barang belum dikonfirmasi diterima gudang.',
            self::Diterima => 'Barang telah dikonfirmasi diterima Pengelola Gudang; stok sudah bertambah.',
            self::Dibatalkan => 'Realisasi tidak dilanjutkan.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::MenungguPenerimaan => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Diterima => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Dibatalkan => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
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
