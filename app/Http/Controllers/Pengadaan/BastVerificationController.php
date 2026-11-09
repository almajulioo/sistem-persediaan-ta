<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\PihakBast;
use App\Http\Controllers\Controller;
use App\Models\ProcurementReceiptSignature;
use App\Support\BastPengadaan;
use Illuminate\Http\Response;

/**
 * Halaman yang terbuka saat QR TTE pada BAST pengadaan dipindai (ADR 0051).
 *
 * Publik, tanpa login: pemeriksa dan auditor tidak punya akun. Isinya lengkap
 * — penanda tangan, para pihak, rincian barang, jumlah, nilai — supaya QR yang
 * ditempel ke dokumen lain yang isinya diubah langsung ketahuan saat
 * dibandingkan. Semuanya dibaca dari isi yang dibekukan, bukan data hidup.
 *
 * Tiga keadaan, dan ketiganya harus bisa dibedakan pembacanya:
 * - **sah**: TTE tidak dicabut dan isinya cocok dengan sidiknya;
 * - **tidak berlaku**: dicabut (beserta alasan dan tanggalnya), atau isinya
 *   tidak cocok lagi dengan sidik yang ditandatangani;
 * - **tidak ditemukan**: kodenya tidak dikenal, dengan status 404.
 *
 * Membaca tidak dicatat di log aktivitas, sama seperti halaman baca lainnya.
 */
class BastVerificationController extends Controller
{
    public function __invoke(string $kode): Response
    {
        $ttd = strlen($kode) === 40
            ? ProcurementReceiptSignature::query()
                ->with('receipt.signatures', 'receipt.procurement')
                ->where('verification_code', $kode)
                ->first()
            : null;

        if ($ttd === null) {
            return response()->view('pages.verifikasi.bast', [
                'title' => 'Verifikasi BAST',
                'keadaan' => 'tidak_ditemukan',
                'ttd' => null,
                'isi' => null,
                'pihak' => [],
            ], 404);
        }

        $receipt = $ttd->receipt;
        $sah = $ttd->sah();

        // Isi dokumen hanya ditampilkan untuk TTE yang sah. TTE yang dicabut
        // menunjuk isi yang sudah tidak berlaku, dan isi yang tidak cocok
        // dengan sidiknya justru yang tidak boleh dipercaya.
        $pihak = [];

        if ($sah) {
            foreach (PihakBast::cases() as $p) {
                $pihak[$p->value] = ['pihak' => $p, 'ttd' => $receipt->tteAktif($p)];
            }
        }

        return response()->view('pages.verifikasi.bast', [
            'title' => 'Verifikasi BAST',
            'keadaan' => $sah ? 'sah' : 'tidak_berlaku',
            'ttd' => $ttd,
            'isi' => $sah ? $receipt->content : null,
            'sidik' => $sah ? $receipt->content_hash : null,
            'pihak' => $pihak,
            'lengkap' => $sah && $receipt->procurement?->isDiterima(),
            'tanggal' => $sah ? BastPengadaan::tanggal($receipt->content['tanggal'] ?? null) : null,
        ]);
    }
}
