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
}
