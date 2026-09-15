<?php

namespace App\Enums;

/**
 * Status satu usulan barang baru, disimpan pada `item_proposals.status`.
 *
 * Hanya tiga keadaan, dan keduanya yang terakhir final: menyetujui usulan
 * berarti barangnya sudah terdaftar di master barang, sedangkan menolak berarti
 * barangnya tidak akan didaftarkan — keduanya tidak punya langkah lanjutan.
 * Berbeda dari ProcurementStatus yang perlu membedakan "belum berlaku" dari
 * "sudah berlaku". Lihat ADR 0032.
 */
enum ItemProposalStatus: string
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
            self::Diajukan => 'Menunggu keputusan Pengelola Gudang, yang memeriksa apakah barangnya benar-benar belum ada.',
            self::Disetujui => 'Barangnya sudah didaftarkan di master barang dan siap diadakan.',
            self::Ditolak => 'Barangnya tidak didaftarkan; alasannya tercatat pada keterangan keputusan.',
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
     * @return array<string, string> nilai => label, untuk dropdown.
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
