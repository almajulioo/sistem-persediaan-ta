<?php

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;

/**
 * Kode QR sebagai data URI PNG, untuk disematkan ke PDF dompdf.
 *
 * Paket dan pengaturannya sama dengan QR Nota Dinas milik Alma
 * (`endroid/qr-code`, PNG lewat GD, koreksi galat High) supaya kedua sub sistem
 * mencetak QR yang serupa. Data URI dipakai karena dompdf membatasi berkas
 * lokal pada chroot-nya. Lihat ADR 0051.
 */
class KodeQr
{
    public static function dataUri(string $isi, int $ukuran = 300): string
    {
        return (new Builder)->build(
            data: $isi,
            // High: cetakan yang terlipat atau ternoda tetap terbaca.
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $ukuran,
            margin: 0,
        )->getDataUri();
    }
}
