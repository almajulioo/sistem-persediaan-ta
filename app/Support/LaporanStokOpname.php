<?php

namespace App\Support;

use App\Models\OpnameDetail;
use App\Models\StockOpname;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Laporan Stok Opname dalam Excel (ADR 0055), menggantikan PDF.
 *
 * Isinya sama dengan laporan PDF sebelumnya — kop, identitas dokumen,
 * rekap, rincian beserta alasan selisih — ditambah dua kolom yang dulu hanya
 * berupa catatan kecil di bawah nama barang: stok saat posting dan cara
 * barang dicacah (diisi petugas atau mengikuti stok sistem, ADR 0056).
 *
 * Opname yang sudah diposting ditutup satu ruang tanda tangan "Mengetahui"
 * untuk Kepala Subbagian Umum dan TI, dengan nama yang sengaja dibiarkan
 * titik-titik untuk ditulis tangan (ADR 0057). Draf tidak mendapatkannya:
 * belum ada yang perlu ditandatangani.
 */
class LaporanStokOpname
{
    use LembarExcel;

    /** Selisih dan penyesuaian bertanda, mis. "+3" dan "-2". */
    private const FORMAT_SELISIH = '+#,##0;-#,##0;0';

    private const KOLOM_STOK_SAAT_POSTING = 9;

    /** Ruang tanda tangan menempati kolom Pencacahan sampai Keterangan. */
    private const KOLOM_TANDA_TANGAN = [10, 12];

    /**
     * @param  StockOpname  $opname  memuat `user` dan `details.item`
     */
    public static function laporan(StockOpname $opname, User $pencetak): Spreadsheet
    {
        [$spreadsheet, $sheet] = self::buku(
            $opname->judul(),
            'Stok Opname',
            [6, 18, 40, 11, 13, 12, 11, 13, 15, 22, 24, 42],
        );

        $ringkasan = $opname->ringkasan();

        $baris = self::kop($sheet, 'Laporan Stok Opname Persediaan', [
            'Badan Pemeriksa Keuangan Republik Indonesia Perwakilan Provinsi Lampung',
            $opname->period_type->label().' '.$opname->period_year,
        ], $pencetak);

        $baris = self::pasangan($sheet, $baris, array_filter([
            'Tanggal Cacah' => $opname->opname_date->translatedFormat('d F Y'),
            'Petugas' => $opname->user?->fullname ?? '—',
            'Status' => $opname->status->label(),
            'Diposting' => $opname->posted_at ?? 'Belum diposting',
            'Catatan' => $opname->notes,
        ], fn ($nilai) => filled($nilai)));

        // Barang kosong baru "mengikuti stok sistem" setelah opname insidental
        // diposting; selain itu ia belum diisi (ADR 0056, 0057).
        $baris = self::pasangan($sheet, $baris, [
            'Jumlah Barang' => $ringkasan['barang'],
            'Diisi Petugas' => $ringkasan['diisi'],
            ($opname->kosongMengikutiStokSistem() ? 'Mengikuti Stok Sistem' : 'Belum Diisi') => $ringkasan['tidak_diisi'],
            'Barang Berselisih' => $ringkasan['selisih'],
            'Selisih Lebih' => $ringkasan['lebih'],
            'Selisih Kurang' => $ringkasan['kurang'],
        ]);

        $rincian = $opname->details->values();
        $awalTabel = $baris;

        $barisAkhir = self::tabel(
            $sheet,
            $baris,
            ['No', 'Kode Barang', 'Nama Barang', 'Satuan', 'Stok Sistem', 'Stok Fisik', 'Selisih', 'Penyesuaian', 'Stok Saat Posting', 'Pencacahan', 'Alasan Selisih', 'Keterangan'],
            $rincian->map(fn (OpnameDetail $detail, int $i) => [
                $i + 1,
                $detail->item?->item_code,
                $detail->item?->item_name ?? 'Barang tidak dikenal',
                $detail->item?->unit->label(),
                $detail->stokSistem(),
                $detail->stock_real,
                $detail->difference,
                $detail->adjustment_amount,
                $detail->stock_at_posting,
                self::pencacahan($detail),
                $detail->adjustment_reason?->label(),
                $detail->adjustment_note,
            ])->all(),
            kosong: 'Tidak ada barang pada opname ini.',
            formatKolom: [7 => self::FORMAT_SELISIH, 8 => self::FORMAT_SELISIH],
        );

        $berubah = $rincian->filter(fn (OpnameDetail $detail) => $detail->stokBerubahSejakDicacah());

        foreach ($berubah->keys() as $i) {
            $sheet->getStyle([self::KOLOM_STOK_SAAT_POSTING, $awalTabel + 1 + $i])->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFEF0C7');
        }

        $catatan = array_values(array_filter([
            $berubah->isNotEmpty()
                ? 'Catatan: sebagian barang mengalami perubahan stok setelah dicacah (Stok Saat Posting berwarna), misalnya karena ada penyerahan barang saat opname berlangsung. Yang dikoreksi opname hanyalah selisih yang ditemukan saat mencacah, sehingga transaksi sah setelah pencacahan tidak ikut dibatalkan.'
                : null,
            $opname->isSelesai() && $ringkasan['tidak_diisi'] > 0
                ? 'Catatan: barang berketerangan "Mengikuti stok sistem" tidak diisi petugas saat pencacahan dan dianggap sesuai dengan stok sistem, sehingga tidak ada penyesuaian untuknya.'
                : null,
        ]));

        $baris = $barisAkhir + 2;

        foreach ($catatan as $teks) {
            self::tulis($sheet, [1, $baris++], $teks);
        }

        if ($opname->isSelesai()) {
            self::tandaTangan($sheet, $baris + 1, $opname);
        }

        return $spreadsheet;
    }

    public static function namaBerkas(StockOpname $opname): string
    {
        return 'laporan-opname-'.$opname->period_year.'-'.$opname->period_type->value.'.xlsx';
    }

    /**
     * Tempat dan tanggal posting, jabatan, ruang tanda tangan, lalu
     * titik-titik nama. Nama tidak diambil dari akun mana pun (ADR 0057).
     */
    private static function tandaTangan(Worksheet $sheet, int $baris, StockOpname $opname): void
    {
        [$awal, $akhir] = self::KOLOM_TANDA_TANGAN;

        $isi = [
            // posted_at selalu terisi oleh OpnamePoster; titik-titik hanya
            // penjaga untuk data yang ditulis di luar alur posting.
            'Bandar Lampung, '.($opname->posted_at?->translatedFormat('j F Y') ?? '....................'),
            'Mengetahui,',
            'Kepala Subbagian Umum dan TI',
            null,
            null,
            null,
            null,
            '(....................................)',
        ];

        foreach ($isi as $i => $teks) {
            $sheet->mergeCells([$awal, $baris + $i, $akhir, $baris + $i]);
            $sheet->getStyle([$awal, $baris + $i, $akhir, $baris + $i])
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            self::tulis($sheet, [$awal, $baris + $i], $teks);
        }
    }

    private static function pencacahan(OpnameDetail $detail): string
    {
        return match (true) {
            $detail->diisiPetugas() => 'Diisi petugas',
            $detail->mengikutiStokSistem() => 'Mengikuti stok sistem',
            default => 'Belum diisi',
        };
    }
}
