<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\ProcurementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\CancelProcurementRequest;
use App\Http\Requests\Pengadaan\StoreProcurementRequest;
use App\Models\Procurement;
use App\Models\ProcurementDetail;
use App\Models\ProcurementRequest;
use App\Models\Supplier;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Realisasi pengadaan — dibuat Pejabat Pengadaan dari satu usulan yang sudah
 * disetujui, menautkannya ke penyedia. Berhenti di status MenungguPenerimaan
 * sampai Pengelola Gudang mengonfirmasi penerimaan lewat
 * ProcurementReceiptController; stok baru bertambah saat itu. Lihat ADR 0008,
 * ADR 0015, dan ADR 0016.
 *
 * Mengurus pula keluaran cetak Tahap 4: BAST per realisasi, serta laporan
 * pengadaan PDF dan CSV yang isinya mengikuti penyaring yang sama dengan
 * layar (ADR 0009).
 */
class ProcurementController extends Controller
{
    private const PER_PAGE = 10;

    /**
     * Batas aman ekspor. Lebih kecil daripada batas laporan mutasi karena satu
     * realisasi membawa seluruh rincian barangnya, bukan satu baris.
     */
    private const EXPORT_LIMIT = 2000;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('pages.procurements.index', [
            'title' => 'Realisasi Pengadaan',
            'procurements' => $this->filteredQuery($filters)
                ->with(['supplier', 'details'])
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => $this->summary($filters),
            // Termasuk penyedia yang sudah diarsipkan: realisasi lama tetap
            // atas namanya dan harus tetap dapat disaring.
            'suppliers' => Supplier::query()
                ->orderBy('supplier_name')
                ->pluck('supplier_name', 'supplier_id')
                ->all(),
        ]);
    }

    /**
     * Formulir hanya bisa dibuka dari satu usulan yang sudah disetujui dan
     * belum punya realisasi — tombol "Realisasikan" pada halaman usulan.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $procurementRequest = ProcurementRequest::with('details.item')
            ->find((string) $request->query('procurement_request_id', ''));

        if ($procurementRequest === null) {
            return redirect()
                ->route('procurement-requests.index')
                ->with('error', 'Usulan pengadaan tidak ditemukan.');
        }

        if (! $procurementRequest->isDisetujui() || $procurementRequest->procurement()->exists()) {
            return redirect()
                ->route('procurement-requests.show', $procurementRequest)
                ->with('error', 'Usulan ini tidak dapat direalisasikan. Realisasi hanya dari usulan yang sudah disetujui dan belum memiliki realisasi.');
        }

        return view('pages.procurements.create', [
            'title' => 'Realisasi Pengadaan',
            'procurementRequest' => $procurementRequest,
            'rincianDisetujui' => $procurementRequest->details->filter(fn ($detail) => $detail->disetujui())->values(),
            'suppliers' => Supplier::aktif()->orderBy('supplier_name')->get(['supplier_id', 'supplier_name']),
        ]);
    }

    public function store(StoreProcurementRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $rincian = $request->rincianDisetujui();

        $procurement = DB::transaction(function () use ($request, $data, $rincian) {
            $procurement = Procurement::create([
                'procurement_request_id' => $data['procurement_request_id'],
                'supplier_id' => $data['supplier_id'],
                'procurement_date' => $data['procurement_date'],
                'status' => ProcurementStatus::MenungguPenerimaan,
                'user_id' => $request->user()->user_id,
            ]);

            $procurement->details()->createMany(
                array_map(fn (array $item) => [
                    'item_id' => $rincian->get($item['procurement_request_detail_id'])->item_id,
                    'amount' => $item['amount'],
                    'unit_price' => $item['unit_price'],
                ], $data['items']),
            );

            return $procurement;
        });

        AuditLogger::record($request->user(), "Membuat realisasi pengadaan: {$procurement->judul()}");
        NotificationScanner::periksaRealisasi($procurement->fresh('supplier'));

        // Usulannya baru saja berhenti menganggur, jadi tagihan
        // UsulanBelumDirealisasi ikut ditutup seketika alih-alih menunggu
        // sapuan terjadwal — sejalan dengan catatUsulanDiputuskan() yang juga
        // menyegarkan keadaan usulannya sendiri.
        NotificationScanner::periksaUsulan($procurement->procurementRequest);

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "{$procurement->judul()} berhasil dibuat dan menunggu penerimaan barang.");
    }

    public function show(Procurement $procurement): View
    {
        return view('pages.procurements.show', [
            'title' => $procurement->judul(),
            'procurement' => $procurement->load([
                'user', 'supplier', 'procurementRequest', 'details.item',
                'receipt.receivedBy', 'documents.uploadedBy', 'cancelledBy',
            ]),
        ]);
    }

    /**
     * Membatalkan realisasi yang tidak akan pernah sampai ke penerimaan.
     *
     * Hanya mungkin selama masih `MenungguPenerimaan`. Begitu barang diterima,
     * stoknya sudah bertambah lewat mutasi yang immutable (ADR 0005) dan tidak
     * ada lagi yang boleh dibatalkan — karena itu tidak ada satu pun mutasi
     * yang perlu dibalik di sini, dan `StockLedger` tidak tersentuh sama
     * sekali. Lihat ADR 0024.
     */
    public function cancel(CancelProcurementRequest $request, Procurement $procurement): RedirectResponse
    {
        // Ditolak dengan pesan, bukan 500 — pola yang sama dengan penjaga
        // keputusan ganda pada ProcurementRequestController::approve().
        if (! $procurement->isMenungguPenerimaan()) {
            return back()->with(
                'error',
                "{$procurement->judul()} tidak dapat dibatalkan karena sudah dikonfirmasi diterima atau sudah dibatalkan sebelumnya.",
            );
        }

        $alasan = $request->alasan();

        $procurement->forceFill([
            'status' => ProcurementStatus::Dibatalkan,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->user_id,
            'cancel_reason' => $alasan,
            'cancel_note' => $request->input('cancel_note'),
        ])->save();

        AuditLogger::record(
            $request->user(),
            "Membatalkan {$procurement->judul()} dengan alasan {$alasan->label()}",
        );

        // Menutup RealisasiMenungguPenerimaan (syaratnya tidak lagi terpenuhi)
        // sekaligus memberi tahu gudang bahwa barangnya tidak jadi datang.
        NotificationScanner::catatRealisasiDibatalkan(
            $procurement->fresh(['supplier', 'receipt']),
            $request->user(),
        );

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "{$procurement->judul()} berhasil dibatalkan.");
    }

    /**
     * BAST siap tanda tangan, dicetak dari sistem.
     *
     * Isinya memakai `amount_received` — yang benar-benar datang — bukan
     * `amount` yang dipesan. Menandatangani jumlah pesanan atas barang yang
     * datang kurang justru membuat dokumennya berbohong. Lihat ADR 0017.
     *
     * Baru tersedia setelah penerimaan dikonfirmasi: sebelum itu tidak ada
     * yang diserahterimakan, dan nomor BAST-nya pun belum ada.
     */
    public function bast(Request $request, Procurement $procurement): Response|RedirectResponse
    {
        $procurement->load(['supplier', 'details.item', 'receipt.receivedBy']);

        if ($procurement->receipt === null) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', 'BAST baru dapat dicetak setelah penerimaan barang dikonfirmasi.');
        }

        $pdf = Pdf::loadView('pdf.bast-pengadaan', [
            'procurement' => $procurement,
            'receipt' => $procurement->receipt,
            'dicetakOleh' => $request->user()->fullname,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('bast-'.Str::slug($procurement->receipt->bast_number).'.pdf');
    }

    public function exportPdf(Request $request): Response|RedirectResponse
    {
        $filters = $this->filters($request);

        if ($tooMany = $this->guardExportSize($filters)) {
            return $tooMany;
        }

        $pdf = Pdf::loadView('pdf.laporan-pengadaan', [
            'procurements' => $this->rincianLengkap($filters),
            'filters' => $filters,
            'summary' => $this->summary($filters),
            'namaPenyedia' => $this->namaPenyedia($filters),
            'dicetakOleh' => $request->user()->fullname,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('laporan-pengadaan-'.now()->format('Ymd-His').'.pdf');
    }

    public function exportCsv(Request $request): StreamedResponse|RedirectResponse
    {
        $filters = $this->filters($request);

        if ($tooMany = $this->guardExportSize($filters)) {
            return $tooMany;
        }

        $procurements = $this->rincianLengkap($filters);
        $filename = 'laporan-pengadaan-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($procurements) {
            $handle = fopen('php://output', 'wb');

            // BOM UTF-8 supaya Excel membaca karakter Indonesia dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Tanggal', 'Realisasi', 'Penyedia', 'Status', 'Kode Barang', 'Nama Barang',
                'Satuan', 'Dipesan', 'Harga Satuan', 'Nilai Dipesan', 'Diterima',
                'Kurang Kirim', 'Nilai Diterima', 'Nomor BAST', 'Tanggal Diterima',
            ], ';');

            foreach ($procurements as $procurement) {
                foreach ($procurement->details as $detail) {
                    fputcsv($handle, [
                        $procurement->procurement_date->format('d/m/Y'),
                        $procurement->judul(),
                        $procurement->supplier?->supplier_name ?? '-',
                        $procurement->status->label(),
                        $detail->item?->item_code ?? '-',
                        $detail->item?->item_name ?? '-',
                        $detail->item?->unit->label() ?? '-',
                        $detail->amount,
                        $detail->unit_price,
                        $detail->subtotal(),
                        $detail->sudahDiterima() ? $detail->amount_received : '',
                        $detail->kurangKirim(),
                        ($detail->amount_received ?? 0) * $detail->unit_price,
                        $procurement->receipt?->bast_number ?? '',
                        $procurement->receipt?->received_at->format('d/m/Y') ?? '',
                    ], ';');
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Penyaring yang dibaca sekali lalu dipakai bersama oleh layar, PDF, dan
     * CSV supaya isi laporan selalu identik dengan yang dilihat pengguna.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return [
            'status' => (string) $request->query('status', ''),
            'supplier' => (string) $request->query('supplier', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        return Procurement::query()
            ->filter($filters)
            ->latest('procurement_date')
            ->latest('created_at');
    }

    /**
     * Realisasi terpilih beserta seluruh isinya, diurutkan menaik supaya
     * laporan terbaca sebagai kronologi — kebalikan dari daftar di layar yang
     * menaruh yang terbaru di atas.
     *
     * @param  array<string, string>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, Procurement>
     */
    private function rincianLengkap(array $filters)
    {
        return Procurement::query()
            ->filter($filters)
            ->with(['supplier', 'receipt', 'details.item'])
            ->orderBy('procurement_date')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Rekap periode: cacah dokumen menurut status, dan dua sisi nilainya.
     *
     * Nilai dipesan dan nilai diterima sengaja dipisah — selisih keduanya
     * adalah kekurangan kiriman penyedia, angka yang justru hilang bila
     * keduanya dilebur jadi satu. Lihat ADR 0017.
     *
     * @param  array<string, string>  $filters
     * @return array<string, int>
     */
    private function summary(array $filters): array
    {
        $base = Procurement::query()->filter($filters);

        // Subkueri, bukan daftar id yang ditarik lebih dulu: jumlah realisasi
        // yang tersaring tidak dibatasi apa pun di layar daftar.
        $rincian = fn () => ProcurementDetail::query()->whereIn(
            'procurement_id',
            Procurement::query()->filter($filters)->select('procurement_id'),
        );

        return [
            'total' => (clone $base)->count(),
            'menunggu' => (clone $base)->where('status', ProcurementStatus::MenungguPenerimaan)->count(),
            'diterima' => (clone $base)->where('status', ProcurementStatus::Diterima)->count(),
            'nilai' => (int) $rincian()->sum(DB::raw('amount * unit_price')),
            'nilai_diterima' => (int) $rincian()->sum(DB::raw('COALESCE(amount_received, 0) * unit_price')),
        ];
    }

    /**
     * Nama penyedia yang sedang disaring, untuk dicetak di kepala laporan.
     *
     * @param  array<string, string>  $filters
     */
    private function namaPenyedia(array $filters): ?string
    {
        if ($filters['supplier'] === '') {
            return null;
        }

        return Supplier::query()->find($filters['supplier'])?->supplier_name;
    }

    /**
     * Tolak ekspor yang terlalu besar sebelum data dimuat ke memori.
     *
     * @param  array<string, string>  $filters
     */
    private function guardExportSize(array $filters): ?RedirectResponse
    {
        if (Procurement::query()->filter($filters)->count() <= self::EXPORT_LIMIT) {
            return null;
        }

        return back()->with('error', sprintf(
            'Data yang dipilih melebihi %s realisasi. Persempit rentang tanggal terlebih dahulu.',
            number_format(self::EXPORT_LIMIT, 0, ',', '.'),
        ));
    }
}
