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
        return new self('Realisasi ini sudah dikonfirmasi diterima atau dibatalkan, dan tidak dapat diproses ulang.');
    }
}
