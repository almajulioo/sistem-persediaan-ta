<?php

namespace App\Exceptions;

use App\Models\StockOpname;
use RuntimeException;

/**
 * Dilempar saat membuat draf opname baru padahal masih ada draf yang belum
 * diposting (ADR 0057): satu draf aktif dalam satu waktu.
 *
 * Dilempar dari dalam transaksi `KirimanFormulir::sekali()` supaya token
 * kiriman tidak ikut tercatat; controller menerjemahkannya menjadi arahan ke
 * draf yang masih berjalan, bukan 500.
 */
class DrafOpnameMasihAdaException extends RuntimeException
{
    public function __construct(public readonly StockOpname $draf)
    {
        parent::__construct("Masih ada draf {$draf->judul()} yang belum diposting.");
    }
}
