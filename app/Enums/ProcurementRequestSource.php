<?php

namespace App\Enums;

use App\Support\Warna;

/**
 * Asal-usul satu barang pada usulan pengadaan, disimpan per baris pada
 * `procurement_request_details.source` (ADR 0044).
 *
 * Dulu disimpan per dokumen pada `procurement_requests.source` (ADR 0015).
 * Sejak satu draf menampung barang dari ketiga jalur sekaligus (ADR 0027),
 * sumber dokumen hanya mencatat siapa yang kebetulan membuat draf lebih dulu.
 *
 * Jalur keempat, `dari_permintaan`, dihapus: permintaan tidak lagi meneruskan
 * kekurangan stoknya sejak ada permohonan, dan baris lamanya dipetakan ke
 * `DariPermohonan` oleh migrasi.
 */
enum ProcurementRequestSource: string
{
    case DariPermohonan = 'dari_permohonan';
    case Manual = 'manual';
    case PemantauanStok = 'pemantauan_stok';

    public function label(): string
    {
        return match ($this) {
            self::DariPermohonan => 'Permohonan Pengguna',
            self::Manual => 'Manual',
            self::PemantauanStok => 'Pemantauan Stok Otomatis',
        };
    }

    /**
     * Nama warna App\Support\Warna. Indigo permohonan sama dengan tautan
     * nomor permohonan yang sudah tampil di draf dan detail usulan.
     */
    public function warna(): string
    {
        return match ($this) {
            self::DariPermohonan => 'indigo',
            self::Manual => 'teal',
            self::PemantauanStok => 'orange',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DariPermohonan => 'users',
            self::Manual => 'pencil',
            self::PemantauanStok => 'activity',
        };
    }

    public function badgeClass(): string
    {
        return Warna::lembut($this->warna());
    }

    /**
     * Keterangan alasan hanya milik baris manual. Dua sumber lainnya sudah
     * membawa alasannya sendiri: dokumen permohonan, atau stok di bawah minimum.
     */
    public function bolehBerketerangan(): bool
    {
        return $this === self::Manual;
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
