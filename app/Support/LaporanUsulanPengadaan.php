<?php

namespace App\Support;

use App\Enums\ProcurementRequestStatus;
use App\Models\ItemApplicationDetail;
use App\Models\ProcurementRequest;
use App\Models\ProcurementRequestDetail;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * Ekspor Excel usulan pengadaan (ADR 0048): daftar usulan per rentang
 * tanggal diajukan, dan satu usulan beserta rincian barangnya.
 *
 * Isinya sengaja sama dengan tabel di layar — tidak ada kolom yang hanya
 * hidup di berkas ini, dan tidak ada data baru yang dicatat untuknya.
 *
 * Teks selalu ditulis eksplisit sebagai string. Pengikat nilai bawaan
 * PhpSpreadsheet menjadikan teks berawalan "=" sebuah rumus dan kode
 * berbentuk angka kehilangan nol depannya, padahal keterangan barang diketik
 * pengguna.
 */
class LaporanUsulanPengadaan
{
    private const FORMAT_TANGGAL = 'dd/mm/yyyy hh:mm';

    private const FORMAT_ANGKA = '#,##0';

    /**
     * Dua lembar: Ringkasan (satu baris per usulan) dan Rekap Barang (satu
     * baris per barang, digabung dari semua usulan yang sama).
     *
     * @param  Collection<int, ProcurementRequest>  $usulan  sudah tersaring, memuat `user` dan `details.item`
     * @param  array{status: string, from: string, to: string}  $filters
     */
    public static function daftar(Collection $usulan, array $filters, User $pencetak): Spreadsheet
    {
        [$spreadsheet, $sheet] = self::buku('Laporan Usulan Pengadaan', 'Ringkasan', [6, 30, 18, 30, 14, 30]);

        $status = ProcurementRequestStatus::tryFrom($filters['status']);
        $keterangan = array_values(array_filter([
            self::periode($filters),
            $status ? 'Status: '.$status->label() : null,
        ]));
        $baris = self::kop($sheet, 'Laporan Usulan Pengadaan', $keterangan, $pencetak);

        // Label ringkasan sama dengan kartu di atas daftar usulan, tetapi
        // angkanya dihitung dari usulan yang tersaring saja.
        $perStatus = $usulan->countBy(fn (ProcurementRequest $row) => $row->status->value);
        $baris = self::pasangan($sheet, $baris, [
            'Total Usulan' => $usulan->count(),
            'Menunggu Persetujuan' => $perStatus->get(ProcurementRequestStatus::Diajukan->value, 0),
            'Disetujui, Menunggu Realisasi' => $perStatus->get(ProcurementRequestStatus::DisetujuiMenungguRealisasi->value, 0),
            'Disetujui, Selesai' => $perStatus->get(ProcurementRequestStatus::DisetujuiSelesai->value, 0),
            'Ditolak' => $perStatus->get(ProcurementRequestStatus::Ditolak->value, 0),
        ]);

        self::tabel(
            $sheet,
            $baris,
            ['No', 'Nomor Usulan', 'Tanggal Diajukan', 'Pengaju', 'Jumlah Barang', 'Status'],
            $usulan->values()->map(fn (ProcurementRequest $row, int $i) => [
                $i + 1,
                $row->judul(),
                $row->created_at,
                $row->user?->fullname ?? '—',
                $row->details->count(),
                $row->status->label(),
            ])->all(),
            kosong: 'Tidak ada usulan pada periode dan penyaring yang dipilih.',
        );

        $rekap = self::lembar($spreadsheet->createSheet(), 'Rekap Barang', [6, 20, 40, 11, 14, 12, 12, 17]);
        $baris = self::kop($rekap, 'Rekap Barang Usulan Pengadaan', $keterangan, $pencetak);

        self::tabel(
            $rekap,
            $baris,
            ['No', 'Kode Barang', 'Nama Barang', 'Satuan', 'Jumlah Usulan', 'Diminta', 'Disetujui', 'Belum Diputuskan'],
            self::rekapBarang($usulan)->map(fn (array $row, int $i) => [$i + 1, ...array_values($row)])->all(),
            kosong: 'Tidak ada barang pada periode dan penyaring yang dipilih.',
        );

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  ProcurementRequest  $usulan  memuat `user` dan `details.item`
     */
    public static function rincian(ProcurementRequest $usulan, User $pencetak): Spreadsheet
    {
        [$spreadsheet, $sheet] = self::buku('Usulan Pengadaan', 'Usulan Pengadaan', [6, 20, 40, 18, 34, 40, 11, 16, 11]);

        $baris = self::kop($sheet, 'Usulan Pengadaan', [], $pencetak);
        $baris = self::pasangan($sheet, $baris, [
            'Nomor Usulan' => $usulan->judul(),
            'Status' => $usulan->status->label(),
            'Pengaju' => $usulan->user?->fullname ?? '—',
            'Diajukan' => $usulan->created_at,
            'Jumlah Barang' => $usulan->details->count(),
        ]);

        $permohonan = $usulan->permohonanPerBarang();

        // Tanpa baris jumlah: satuan barangnya berbeda-beda, jadi total
        // Diminta/Disetujui tidak bermakna.
        self::tabel(
            $sheet,
            $baris,
            ['No', 'Kode Barang', 'Nama Barang', 'Sumber', 'Permohonan', 'Keterangan', 'Diminta', 'Disetujui', 'Satuan'],
            $usulan->details->values()->map(fn (ProcurementRequestDetail $detail, int $i) => [
                $i + 1,
                $detail->item?->item_code,
                $detail->item?->item_name ?? 'Barang tidak dikenal',
                $detail->source->label(),
                $permohonan->get($detail->item_id, collect())
                    ->map(fn (ItemApplicationDetail $asal) => implode(' · ', [
                        $asal->application->application_number,
                        $asal->application->user?->fullname ?? '-',
                        number_format($asal->quantity, 0, ',', '.'),
                    ]))
                    ->implode("\n"),
                $detail->note,
                $detail->amount_request,
                $detail->sudahDiputuskan() ? $detail->amount_granted : 'Belum diputuskan',
                $detail->item?->unit->label(),
            ])->all(),
        );

        return $spreadsheet;
    }

    public static function unduh(Spreadsheet $spreadsheet, string $namaBerkas): StreamedResponse
    {
        return response()->streamDownload(
            fn () => (new Xlsx($spreadsheet))->save('php://output'),
            $namaBerkas,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Satu baris per barang dari seluruh rincian usulan yang tersaring.
     *
     * Barang yang diusulkan paling sering di atas. Diminta = Disetujui +
     * Belum Diputuskan + yang tidak disetujui; yang terakhir sengaja tidak
     * dijadikan kolom karena persetujuan sebagian membuatnya bukan "ditolak".
     *
     * @param  Collection<int, ProcurementRequest>  $usulan
     * @return Collection<int, array{kode: ?string, nama: string, satuan: ?string, usulan: int, diminta: int, disetujui: int, belum: int}>
     */
    private static function rekapBarang(Collection $usulan): Collection
    {
        return $usulan
            ->flatMap(fn (ProcurementRequest $row) => $row->details)
            ->groupBy('item_id')
            ->map(function (Collection $rincian) {
                $item = $rincian->first()->item;

                return [
                    'kode' => $item?->item_code,
                    'nama' => $item?->item_name ?? 'Barang tidak dikenal',
                    'satuan' => $item?->unit->label(),
                    'usulan' => $rincian->pluck('procurement_request_id')->unique()->count(),
                    'diminta' => (int) $rincian->sum('amount_request'),
                    'disetujui' => (int) $rincian->sum(fn (ProcurementRequestDetail $detail) => $detail->amount_granted ?? 0),
                    'belum' => (int) $rincian
                        ->reject(fn (ProcurementRequestDetail $detail) => $detail->sudahDiputuskan())
                        ->sum('amount_request'),
                ];
            })
            ->sortBy([['usulan', 'desc'], ['kode', 'asc']])
            ->values();
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
     */
    private static function tabel(Worksheet $sheet, int $baris, array $judulKolom, array $isi, string $kosong = ''): void
    {
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

        $sheet->setAutoFilter("A{$baris}:{$kolomAkhir}{$barisAkhir}");
        $sheet->freezePane('A'.($baris + 1));
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($baris, $baris);

        if ($isi === [] && $kosong !== '') {
            self::tulis($sheet, [1, $baris + 1], $kosong);
        }
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

    /**
     * Sama dengan kalimat periode pada laporan PDF realisasi. Tanggalnya
     * sudah dipastikan berbentuk Y-m-d oleh pengendali.
     *
     * @param  array{from: string, to: string}  $filters
     */
    private static function periode(array $filters): string
    {
        $tanggal = fn (string $nilai) => Carbon::parse($nilai)->translatedFormat('d F Y');

        return match (true) {
            $filters['from'] !== '' && $filters['to'] !== '' => $tanggal($filters['from']).' s.d. '.$tanggal($filters['to']),
            $filters['from'] !== '' => 'Sejak '.$tanggal($filters['from']),
            $filters['to'] !== '' => 'Sampai '.$tanggal($filters['to']),
            default => 'Seluruh periode',
        };
    }
}
