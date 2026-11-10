<?php

namespace App\Enums;

use App\Support\Warna;

/**
 * Status permohonan barang baru / tambah stok, disimpan pada
 * `item_applications.status`.
 *
 * Disetujui bertahap: Kepala Unit, Pengelola Gudang, lalu Kasubbag Umum dan TI
 * sebagai keputusan akhir. Tiap tahap boleh menolak, dan Disetujui maupun
 * Ditolak final. Tindak lanjut sesudah disetujui — draf usulan pengadaan dan
 * usulan barang baru — punya statusnya sendiri di dokumen masing-masing.
 */
enum ItemApplicationStatus: string
{
    case Diajukan = 'diajukan';
    case DisetujuiKepalaUnit = 'disetujui_kepala_unit';
    case DitinjauGudang = 'ditinjau_gudang';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Diajukan',
            self::DisetujuiKepalaUnit => 'Disetujui Kepala Unit',
            self::DitinjauGudang => 'Ditinjau Gudang',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Diajukan => 'Menunggu persetujuan Kepala Unit.',
            self::DisetujuiKepalaUnit => 'Menunggu ditinjau Pengelola Gudang.',
            self::DitinjauGudang => 'Menunggu persetujuan akhir Kasubbag Umum dan TI.',
            self::Disetujui => 'Diteruskan ke draf usulan pengadaan dan/atau usulan barang baru.',
            self::Ditolak => 'Tidak diteruskan; alasannya tercatat pada keterangan keputusan.',
        };
    }

    /**
     * Nama warna App\Support\Warna. Tahap tengahnya sewarna dengan tahap
     * padanannya di Permintaan.
     */
    public function warna(): string
    {
        return match ($this) {
            self::Diajukan => 'warning',
            self::DisetujuiKepalaUnit => 'indigo',
            self::DitinjauGudang => 'orange',
            self::Disetujui => 'success',
            self::Ditolak => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Diajukan => 'clock',
            self::DisetujuiKepalaUnit => 'user-check',
            self::DitinjauGudang => 'clipboard-check',
            self::Disetujui => 'check-circle',
            self::Ditolak => 'x-circle',
        };
    }

    public function badgeClass(): string
    {
        return Warna::lembut($this->warna());
    }

    /**
     * Masih menunggu keputusan di salah satu tahap.
     */
    public function menunggu(): bool
    {
        return ! in_array($this, [self::Disetujui, self::Ditolak], true);
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
