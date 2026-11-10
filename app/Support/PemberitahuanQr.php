<?php

namespace App\Support;

use App\Models\ItemApplication;
use App\Models\Request as ModelsRequest;

/**
 * Isi pemberitahuan di modal persetujuan yang menghasilkan QR: dokumen mana,
 * kolom tanda tangan mana, dan apa akibatnya. Satu sumber untuk daftar, detail,
 * dan dasbor, supaya pengguna tahu sebelum menekan tombol konfirmasi.
 *
 * Harus sejalan dengan yang benar-benar dibuat RequestWorkflowService dan
 * ItemApplicationWorkflowService. Tahap tanpa QR mengembalikan null, sehingga
 * modalnya tidak menampilkan pemberitahuan.
 */
class PemberitahuanQr
{
    private const TANDA_TANGAN_DIGITAL = 'Nota Dinas dicetak ulang dengan QR ini sebagai tanda tangan digital Anda.';

    /**
     * Saat menyetujui permintaan pada tahapnya sekarang.
     *
     * @return array{dokumen: string, kolom: string, catatan: string}|null
     */
    public static function persetujuan(ModelsRequest $request): ?array
    {
        return match (true) {
            $request->status === 'submitted' => [
                'dokumen' => 'Nota Dinas '.($request->nd_number ?? $request->documentNumber('ND')),
                'kolom' => $request->user?->unit?->jabatanKepala() ?? 'Pimpinan unit',
                'catatan' => self::TANDA_TANGAN_DIGITAL,
            ],
            $request->status === 'processed_by_staff' => [
                'dokumen' => self::bast($request),
                'kolom' => 'Mengetahui',
                'catatan' => 'BAST terbit sekarang dengan QR ini. Nama dan tanggal PIHAK KESATU diisi saat barang diserahkan Pengelola Gudang.',
            ],
            $request->status === 'in_issuance' => [
                'dokumen' => self::bast($request),
                'kolom' => 'PIHAK KESATU',
                'catatan' => 'BAST dicetak ulang dengan QR ini, nama Anda, dan tanggal serah terima. QR Mengetahui dari tahap sebelumnya tetap. Penyerahan tidak bisa dikembalikan.',
            ],
            $request->status === 'approved_by_head' && $request->type === 'emergency' => [
                'dokumen' => self::bast($request),
                'kolom' => 'PIHAK KESATU',
                'catatan' => 'BAST terbit sekarang, memuat QR ini. Kolom Mengetahui kosong karena permintaan darurat tidak melewati tahap Kasubbag. Penyerahan tidak bisa dikembalikan.',
            ],
            default => null,
        };
    }

    /**
     * Saat penerimaan barang dikonfirmasi pemohon atau ditutup petugas.
     *
     * @return array{dokumen: string, kolom: string, catatan: string}|null
     */
    public static function penerimaan(ModelsRequest $request, string $action): ?array
    {
        return match ($action) {
            'confirm' => [
                'dokumen' => self::bast($request),
                'kolom' => 'PIHAK KEDUA',
                'catatan' => 'BAST dicetak ulang dengan QR ini. Isi dan tanggal BAST tidak berubah.',
            ],
            'close' => [
                'dokumen' => self::bast($request),
                'kolom' => 'PIHAK KEDUA',
                'catatan' => 'QR dibuat atas nama pemohon. Saat dipindai tertulis Anda yang menutup, bukan pemohon yang mengonfirmasi.',
            ],
            default => null,
        };
    }

    /**
     * Saat menyetujui permohonan. Hanya tahap Kepala Unit yang membuat QR.
     *
     * @return array{dokumen: string, kolom: string, catatan: string}|null
     */
    public static function permohonan(ItemApplication $application): ?array
    {
        if (! $application->isDiajukan()) {
            return null;
        }

        return [
            'dokumen' => 'Nota Dinas '.($application->nd_number ?? $application->nomorNotaDinas()),
            'kolom' => $application->user?->unit?->jabatanKepala() ?? 'Pimpinan unit',
            'catatan' => self::TANDA_TANGAN_DIGITAL,
        ];
    }

    /**
     * Saat nomor Nota Dinas yang sudah ber-QR diubah. QR diterbitkan ulang dan
     * token lamanya hilang, jadi salinan yang sudah beredar tidak bisa
     * diverifikasi lagi (RequestDocumentGenerator::renumberNotaDinas()).
     *
     * @return array{dokumen: string, catatan: string}|null
     */
    public static function penerbitanUlang(ModelsRequest $request): ?array
    {
        if (! $request->notaDinasBerQr()) {
            return null;
        }

        return [
            'dokumen' => 'Nota Dinas '.($request->nd_number ?? 'yang belum bernomor'),
            'catatan' => 'Nota Dinas dicetak ulang dengan QR baru, penanda tangannya tetap. QR pada salinan yang sudah dicetak atau diunduh tidak bisa diverifikasi lagi, jadi salinan lama harus diganti.',
        ];
    }

    /**
     * Saat menyetujui permintaan yang belum bernomor, karena nomornya diisi di
     * modal persetujuan itu (RequestWorkflowService::respond()).
     *
     * @return array{dokumen: string, catatan: string}|null
     */
    public static function penerbitanUlangSaatDisetujui(ModelsRequest $request): ?array
    {
        return $request->status === 'approved_by_head' && $request->nd_number === null
            ? self::penerbitanUlang($request)
            : null;
    }

    private static function bast(ModelsRequest $request): string
    {
        return 'BAST '.($request->bast_number ?? $request->documentNumber('BAST'));
    }
}
