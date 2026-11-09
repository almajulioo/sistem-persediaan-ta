<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika stok opname belum memenuhi syarat untuk diposting.
 *
 * Pemanggil bertanggung jawab menerjemahkannya menjadi pesan yang ramah,
 * bukan membiarkannya menjadi 500.
 */
class OpnameTidakSiapException extends RuntimeException
{
    public static function bukanDraf(): self
    {
        return new self('Stok opname ini sudah diposting dan tidak dapat diproses ulang.');
    }

    /**
     * Opname berkala yang masih menyisakan barang kosong (ADR 0057). Hanya
     * opname insidental yang boleh memposting barang kosong.
     */
    public static function masihAdaYangBelumDiisi(int $jumlah): self
    {
        return new self(sprintf(
            'Masih ada %d barang yang belum diisi. Lengkapi seluruh hasil cacah sebelum memposting.',
            $jumlah,
        ));
    }

    public static function selisihTanpaAlasan(string $itemCode): self
    {
        return new self(sprintf(
            'Barang %s memiliki selisih tetapi belum diberi alasan dan keterangan.',
            $itemCode,
        ));
    }
}
