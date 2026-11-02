<?php

namespace App\Enums;

/**
 * Jenis satu baris riwayat permintaan, disimpan pada `request_histories.action`.
 */
enum RequestHistoryAction: string
{
    case Diajukan = 'diajukan';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
    // Dibatalkan sistem karena tidak diputuskan Kepala Unit tepat waktu.
    case Kedaluwarsa = 'kedaluwarsa';
    case KuantitasDiubah = 'kuantitas_diubah';
    case Dikembalikan = 'dikembalikan';
    case Digabung = 'digabung';

    // --- Konfirmasi penerimaan, setelah barang diserahkan ---
    case Diterima = 'diterima';
    case MasalahDilaporkan = 'masalah_dilaporkan';
    case MasalahDitanggapi = 'masalah_ditanggapi';
    case DitutupPetugas = 'ditutup_petugas';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Diajukan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::Kedaluwarsa => 'Dibatalkan Otomatis',
            self::KuantitasDiubah => 'Kuantitas Diubah',
            self::Dikembalikan => 'Status Dikembalikan',
            self::Digabung => 'Digabung',
            self::Diterima => 'Penerimaan Dikonfirmasi Pemohon',
            self::MasalahDilaporkan => 'Masalah Penerimaan Dilaporkan',
            self::MasalahDitanggapi => 'Masalah Penerimaan Ditanggapi',
            self::DitutupPetugas => 'Ditutup Petugas atas Nama Pemohon',
        };
    }

    /**
     * Langkah yang dilakukan sistem, bukan seorang pengguna.
     */
    public function olehSistem(): bool
    {
        return $this === self::Kedaluwarsa;
    }

    /**
     * Warna titik pada linimasa riwayat.
     */
    public function dotClass(): string
    {
        return match ($this) {
            self::Diajukan, self::Digabung, self::MasalahDitanggapi => 'bg-brand-500',
            self::Disetujui, self::Diterima => 'bg-success-500',
            self::Ditolak, self::Kedaluwarsa, self::MasalahDilaporkan => 'bg-error-500',
            self::KuantitasDiubah, self::Dikembalikan, self::DitutupPetugas => 'bg-warning-500',
        };
    }
}
