<?php

namespace App\Enums;

/**
 * Status permohonan barang baru / tambah stok, disimpan pada
 * `item_applications.status`.
 *
 * Diputuskan sekali oleh Pengelola Gudang dan keduanya final. Tindak lanjut
 * sesudah disetujui — draf usulan pengadaan dan usulan barang baru — punya
 * statusnya sendiri di dokumen masing-masing.
 */
enum ItemApplicationStatus: string
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
            self::Diajukan => 'Menunggu ditinjau Pengelola Gudang.',
            self::Disetujui => 'Diteruskan ke draf usulan pengadaan dan/atau usulan barang baru.',
            self::Ditolak => 'Tidak diteruskan; alasannya tercatat pada keterangan keputusan.',
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
