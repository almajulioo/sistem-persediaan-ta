<?php

namespace App\Enums;

use App\Support\Warna;

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

    /**
     * Nama warna App\Support\Warna.
     */
    public function warna(): string
    {
        return match ($this) {
            self::Diajukan => 'warning',
            self::Disetujui => 'success',
            self::Ditolak => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Diajukan => 'clock',
            self::Disetujui => 'check-circle',
            self::Ditolak => 'x-circle',
        };
    }

    public function badgeClass(): string
    {
        return Warna::lembut($this->warna());
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
