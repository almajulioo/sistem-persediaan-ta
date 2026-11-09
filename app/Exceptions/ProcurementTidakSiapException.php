<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika realisasi pengadaan belum memenuhi syarat untuk diproses.
 *
 * Pemanggil bertanggung jawab menerjemahkannya menjadi pesan yang ramah,
 * bukan membiarkannya menjadi 500.
 */
class ProcurementTidakSiapException extends RuntimeException
{
    public static function bukanMenungguPenerimaan(): self
    {
        return new self('Realisasi ini belum siap diterima, sudah dikonfirmasi diterima, atau dibatalkan.');
    }

    /**
     * Barang belum dibelanjakan, sehingga belum ada yang dapat diterima gudang
     * (ADR 0035). Dipisah dari pesan umum di atas karena inilah satu-satunya
     * penolakan yang jalan keluarnya ada di tangan orang lain.
     */
    public static function belumDibelanjakan(): self
    {
        return new self('Barang realisasi ini belum ditandai sudah dibelanjakan. Penerimaan baru dapat dikonfirmasi setelah Pejabat Pengadaan menandainya.');
    }

    // --- BAST digital (ADR 0051) ---

    public static function belumDiperiksa(): self
    {
        return new self('Hasil pemeriksaan barang belum dicatat lengkap oleh Pengelola Gudang.');
    }

    public static function belumBernomor(): self
    {
        return new self('Isi nomor BAST terlebih dahulu.');
    }

    public static function sudahDitandatangani(string $pihak): self
    {
        return new self("BAST ini sudah ditandatangani sebagai {$pihak}.");
    }

    public static function bukanPenandaTangan(): self
    {
        return new self('Akun Anda tidak menandatangani BAST pengadaan.');
    }

    /**
     * Isi yang dibekukan tidak lagi cocok dengan sidiknya — barisnya diubah di
     * luar aplikasi. Menandatangani di atasnya berarti mengesahkan isi yang
     * tidak pernah dilihat pihak pertama.
     */
    public static function isiTidakUtuh(): self
    {
        return new self('Isi BAST tidak cocok dengan sidik dokumennya. Hubungi Admin.');
    }
}
