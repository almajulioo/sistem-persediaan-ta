<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pembantu bersama laporan Excel: kop, pasangan label–nilai, tabel bergaris,
 * dan unduhan. Dipakai ekspor usulan pengadaan (ADR 0048) dan laporan stok
 * opname (ADR 0055), supaya kedua berkas berpenampilan sama.
 *
 * Teks selalu ditulis eksplisit sebagai string. Pengikat nilai bawaan
 * PhpSpreadsheet menjadikan teks berawalan "=" sebuah rumus dan kode
 * berbentuk angka kehilangan nol depannya, padahal keterangan diketik
 * pengguna.
 */
trait LembarExcel
{
    private const FORMAT_TANGGAL = 'dd/mm/yyyy hh:mm';

    private const FORMAT_ANGKA = '#,##0';

    public static function unduh(Spreadsheet $spreadsheet, string $namaBerkas): StreamedResponse
    {
        return response()->streamDownload(
            fn () => (new Xlsx($spreadsheet))->save('php://output'),
            $namaBerkas,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * @param  list<float|int>  $lebarKolom
     * @return array{0: Spreadsheet, 1: Worksheet}
     */
    private static function buku(string $judulBerkas, string $judulLembar, array $lebarKolom): array
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setCreator((string) config('app.name'))->setTitle($judulBerkas);

        return [$spreadsheet, self::lembar($spreadsheet->getActiveSheet(), $judulLembar, $lebarKolom)];
    }

    /**
     * @param  list<float|int>  $lebarKolom
     */
    private static function lembar(Worksheet $sheet, string $judul, array $lebarKolom): Worksheet
    {
        $sheet->setTitle($judul);

        foreach ($lebarKolom as $i => $lebar) {
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth($lebar);
        }

        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        return $sheet;
    }

    /**
     * Judul, baris keterangan, dan siapa yang mencetak.
     *
     * @param  list<string>  $keterangan
     * @return int baris pertama setelah kop dan satu baris kosong
     */
    private static function kop(Worksheet $sheet, string $judul, array $keterangan, User $pencetak): int
    {
        self::tulis($sheet, [1, 1], $judul);
        $sheet->getStyle([1, 1])->getFont()->setBold(true)->setSize(14);

        $baris = 2;
        $keterangan[] = 'Dicetak '.now()->translatedFormat('d F Y H:i').' oleh '.$pencetak->fullname;

        foreach ($keterangan as $teks) {
            self::tulis($sheet, [1, $baris++], $teks);
        }

        return $baris + 1;
    }

    /**
     * Label di kolom A — meluber ke kolom B yang dibiarkan kosong — dan
     * nilainya di kolom C.
     *
     * @param  array<string, string|int|CarbonInterface>  $pasangan
     * @return int baris pertama setelah pasangan dan satu baris kosong
     */
    private static function pasangan(Worksheet $sheet, int $baris, array $pasangan): int
    {
        foreach ($pasangan as $label => $nilai) {
            self::tulis($sheet, [1, $baris], $label);
            self::tulis($sheet, [3, $baris], $nilai);
            $sheet->getStyle([3, $baris])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $baris++;
        }

        return $baris + 1;
    }

    /**
     * Tabel bergaris dengan judul kolom yang dibekukan, bisa disaring, dan
     * diulang di setiap halaman cetak.
     *
     * @param  list<string>  $judulKolom
     * @param  list<list<string|int|CarbonInterface|null>>  $isi
     * @param  array<int, string>  $formatKolom  format angka per kolom (mulai 1), menimpa format baku
     * @return int baris terakhir tabel
     */
    private static function tabel(
        Worksheet $sheet,
        int $baris,
        array $judulKolom,
        array $isi,
        string $kosong = '',
        array $formatKolom = [],
    ): int {
        $kolomAkhir = Coordinate::stringFromColumnIndex(count($judulKolom));
        $barisAkhir = $baris + count($isi);

        foreach ($judulKolom as $i => $teks) {
            self::tulis($sheet, [$i + 1, $baris], $teks);
        }

        foreach ($isi as $n => $nilai) {
            foreach ($nilai as $i => $sel) {
                self::tulis($sheet, [$i + 1, $baris + 1 + $n], $sel);
            }
        }

        $sheet->getStyle("A{$baris}:{$kolomAkhir}{$baris}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
        ]);
        $sheet->getStyle("A{$baris}:{$kolomAkhir}{$barisAkhir}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]);

        if ($isi !== []) {
            foreach ($formatKolom as $kolom => $format) {
                $huruf = Coordinate::stringFromColumnIndex($kolom);
                $sheet->getStyle($huruf.($baris + 1).":{$huruf}{$barisAkhir}")->getNumberFormat()->setFormatCode($format);
            }
        }

        $sheet->setAutoFilter("A{$baris}:{$kolomAkhir}{$barisAkhir}");
        $sheet->freezePane('A'.($baris + 1));
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($baris, $baris);

        if ($isi === [] && $kosong !== '') {
            self::tulis($sheet, [1, $baris + 1], $kosong);
        }

        return $barisAkhir;
    }

    /**
     * @param  array{0: int, 1: int}  $sel  [kolom, baris]
     */
    private static function tulis(Worksheet $sheet, array $sel, string|int|CarbonInterface|null $nilai): void
    {
        if ($nilai === null || $nilai === '') {
            return;
        }

        if ($nilai instanceof CarbonInterface) {
            $sheet->setCellValueExplicit($sel, TanggalExcel::dateTimeToExcel($nilai), DataType::TYPE_NUMERIC);
            $sheet->getStyle($sel)->getNumberFormat()->setFormatCode(self::FORMAT_TANGGAL);

            return;
        }

        if (is_int($nilai)) {
            $sheet->setCellValueExplicit($sel, $nilai, DataType::TYPE_NUMERIC);
            $sheet->getStyle($sel)->getNumberFormat()->setFormatCode(self::FORMAT_ANGKA);

            return;
        }

        $sheet->setCellValueExplicit($sel, $nilai, DataType::TYPE_STRING);
    }
}
