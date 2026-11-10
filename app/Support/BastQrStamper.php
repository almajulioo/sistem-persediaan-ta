<?php

namespace App\Support;

use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Menempelkan QR verifikasi ke kolom tanda tangan BAST yang sudah jadi.
 *
 * Template BAST tidak punya tempat gambar dan tidak boleh diubah, jadi QR
 * disisipkan ke DOCX hasil TemplateProcessor lewat DocxQrStamper. Di sini
 * hanya ditentukan paragraf, ukuran, dan posisi tiap kolomnya.
 */
class BastQrStamper
{
    public const MENGETAHUI = 'mengetahui';

    public const PIHAK_KESATU = 'pihak_kesatu';

    public const PIHAK_KEDUA = 'pihak_kedua';

    /**
     * Sisi QR dalam EMU (360.000 EMU = 1 cm).
     */
    private const SISI = 612000;

    /**
     * Ruang di bawah jabatan Mengetahui hanya sekitar 1,4 cm.
     */
    private const SISI_MENGETAHUI = 504000;

    /**
     * Jarak QR Mengetahui dari tepi kiri selnya, supaya berada di tengah bawah
     * jabatan. Tidak bisa rata tengah sel: sel itu jauh lebih lebar dari
     * teksnya, dan teks jabatannya rata kiri.
     */
    private const KIRI_MENGETAHUI = 621000;

    /**
     * @param  array<string, string>  $qr  kolom => isi PNG
     */
    public function stamp(string $docxPath, array $qr): void
    {
        if ($qr === []) {
            return;
        }

        (new DocxQrStamper)->stamp($docxPath, function (DOMXPath $xpath) use ($qr): array {
            $kolom = $this->kolomTandaTangan($xpath);
            $tempelan = [];

            foreach ($qr as $posisi => $png) {
                $mengetahui = $posisi === self::MENGETAHUI;
                $tempelan[] = [
                    'nama' => $posisi,
                    'paragraf' => $kolom[$posisi],
                    'png' => $png,
                    'sisi' => $mengetahui ? self::SISI_MENGETAHUI : self::SISI,
                    'mendatar' => $mengetahui ? DocxQrStamper::dariKiri(self::KIRI_MENGETAHUI) : DocxQrStamper::tengahKolom(),
                ];
            }

            return $tempelan;
        });
    }

    /**
     * Paragraf kosong tempat tiap QR, dicari dari teks kolomnya. Gagal keras
     * bila template berubah, sama dengan pemeriksaan placeholder BAST.
     *
     * @return array<string, DOMElement>
     */
    private function kolomTandaTangan(DOMXPath $xpath): array
    {
        $selTandaTangan = $xpath->query("//w:tr[.//w:p[contains(., 'PIHAK KESATU')]]/following-sibling::w:tr[1]/w:tc");
        $kolom = [
            self::PIHAK_KESATU => $selTandaTangan->length > 0 ? $xpath->query('./w:p', $selTandaTangan->item(0))->item(0) : null,
            // Sel ketiga, bukan terakhir: sel terakhir baris ini di luar kertas.
            self::PIHAK_KEDUA => $selTandaTangan->length > 2 ? $xpath->query('./w:p', $selTandaTangan->item(2))->item(0) : null,
            self::MENGETAHUI => $xpath->query("//w:tr//w:p[contains(., 'Kepala Subbagian Umum')]/following-sibling::w:p[1]")->item(0),
        ];

        foreach ($kolom as $paragraf) {
            if (! $paragraf instanceof DOMElement) {
                throw new RuntimeException('Kolom tanda tangan pada template BAST tidak ditemukan.');
            }
        }

        return $kolom;
    }
}
