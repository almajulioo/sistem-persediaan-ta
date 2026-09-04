<?php

namespace App\Enums;

/**
 * Status persetujuan usulan pengadaan, disimpan pada
 * `procurement_requests.status`.
 *
 * Terpisah dari `ProcurementStatus`: usulan dan realisasi adalah dua dokumen
 * berbeda. Usulan yang Disetujui melahirkan satu `Procurement`, yang punya
 * alur statusnya sendiri.
 */
enum ProcurementRequestStatus: string
{
    case Diajukan = 'diajukan';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Diajukan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Diajukan => 'Menunggu persetujuan Pejabat Pengadaan.',
            self::Disetujui => 'Disetujui, siap direalisasikan ke penyedia.',
            self::Ditolak => 'Tidak dilanjutkan ke realisasi pengadaan.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Diajukan => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Disetujui => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Ditolak => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
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
