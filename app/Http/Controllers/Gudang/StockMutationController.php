<?php

namespace App\Http\Controllers\Gudang;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gudang\StoreMutationRequest;
use App\Models\Item;
use App\Models\StockMutation;
use App\Support\AuditLogger;
use App\Support\StockLedger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Riwayat mutasi stok dan pencatatan mutasi manual.
 *
 * Tidak menyediakan aksi ubah maupun hapus: baris mutasi adalah jejak audit dan
 * bersifat immutable. Kesalahan pencatatan dikoreksi dengan mutasi lawan.
 */
class StockMutationController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * Batas aman ekspor supaya satu permintaan tidak menghabiskan memori.
     */
    private const EXPORT_LIMIT = 5000;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('pages.mutations.index', [
            'title' => 'Riwayat Mutasi',
            'mutations' => $this->filteredQuery($filters)
                ->with(['item', 'user'])
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => $this->summary($filters),
        ]);
    }

    public function create(Request $request): View
    {
        return view('pages.mutations.create', [
            'title' => 'Catat Mutasi Manual',
            'items' => Item::aktif()->orderBy('item_code')->get(),
            'selectedItem' => Item::aktif()->find($request->query('item')),
        ]);
    }

    public function store(StoreMutationRequest $request): RedirectResponse
    {
        $item = Item::findOrFail($request->input('item_id'));
        $type = $request->mutationType();

        try {
            StockLedger::record(
                item: $item,
                type: $type,
                amount: $request->signedAmount(),
                recordedBy: $request->user(),
                notes: $request->input('notes'),
                date: $request->date('mutation_date'),
            );
        } catch (InsufficientStockException $e) {
            // Diterjemahkan menjadi galat validasi supaya pengguna melihat pesan
            // yang jelas di kolom jumlah, bukan halaman 500.
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        AuditLogger::record(
            $request->user(),
            "Mencatat mutasi manual {$type->label()} untuk barang {$item->item_code}",
        );

        return redirect()
            ->route('mutations.index')
            ->with('status', "Mutasi {$type->label()} untuk {$item->item_code} berhasil dicatat.");
    }

    public function exportCsv(Request $request): StreamedResponse|RedirectResponse
    {
        $filters = $this->filters($request);
        $query = $this->filteredQuery($filters)->with(['item', 'user']);

        if ($tooMany = $this->guardExportSize($query)) {
            return $tooMany;
        }

        $filename = 'riwayat-mutasi-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            // BOM UTF-8 supaya Excel membaca karakter Indonesia dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Tanggal', 'Kode Barang', 'Nama Barang', 'Satuan', 'Jenis Mutasi',
                'Jumlah', 'Stok Setelah', 'Referensi', 'Petugas', 'Keterangan',
            ], ';');

            $query->reorder()->orderBy('created_at')
                ->chunk(500, function ($rows) use ($handle) {
                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row->mutation_date->format('d/m/Y'),
                            $row->item->item_code,
                            $row->item->item_name,
                            $row->item->unit->label(),
                            $row->mutation_type->label(),
                            $row->amount,
                            $row->stock_after,
                            $row->referensi(),
                            $row->petugas(),
                            $row->notes ?? '',
                        ], ';');
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request): Response|RedirectResponse
    {
        $filters = $this->filters($request);
        $query = $this->filteredQuery($filters)->with(['item', 'user']);

        if ($tooMany = $this->guardExportSize($query)) {
            return $tooMany;
        }

        $pdf = Pdf::loadView('pdf.laporan-mutasi', [
            'mutations' => $query->reorder()->orderBy('created_at')->get(),
            'filters' => $filters,
            'summary' => $this->summary($filters),
            'dicetakOleh' => $request->user()->fullname,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('riwayat-mutasi-'.now()->format('Ymd-His').'.pdf');
    }

    /**
     * Kartu persediaan satu barang — dokumen persediaan baku, memakai
     * infrastruktur PDF yang sama.
     */
    public function kartuPersediaan(Request $request, Item $item): Response
    {
        $pdf = Pdf::loadView('pdf.kartu-persediaan', [
            'item' => $item,
            'mutations' => $item->stockMutations()
                ->with('user')
                ->orderBy('created_at')
                ->get(),
            'dicetakOleh' => $request->user()->fullname,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('kartu-persediaan-'.$item->item_code.'.pdf');
    }

    /**
     * Penyaring yang dibaca sekali lalu dipakai bersama oleh layar, CSV, dan PDF
     * supaya isi laporan selalu identik dengan yang dilihat pengguna.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'type' => (string) $request->query('type', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        // Diurutkan menurut urutan pencatatan, bukan tanggal transaksi: kolom
        // stock_after adalah saldo pada saat mutasi dicatat, sehingga hanya
        // koheren bila dibaca dalam urutan yang sama. Tanggal mutasi boleh
        // mundur, jadi kedua urutan tidak selalu sama.
        return StockMutation::query()
            ->filter($filters)
            ->latest('created_at');
    }

    /**
     * Rekap periode: total masuk, total keluar, netto, dan jumlah transaksi.
     *
     * @param  array<string, string>  $filters
     * @return array<string, int>
     */
    private function summary(array $filters): array
    {
        $base = StockMutation::query()->filter($filters);

        $masuk = (int) (clone $base)->where('amount', '>', 0)->sum('amount');
        $keluar = (int) (clone $base)->where('amount', '<', 0)->sum('amount');

        return [
            'masuk' => $masuk,
            'keluar' => abs($keluar),
            'netto' => $masuk + $keluar,
            'transaksi' => (clone $base)->count(),
        ];
    }

    /**
     * Tolak ekspor yang terlalu besar sebelum data dimuat ke memori.
     */
    private function guardExportSize(Builder $query): ?RedirectResponse
    {
        if ((clone $query)->count() <= self::EXPORT_LIMIT) {
            return null;
        }

        return back()->with('error', sprintf(
            'Data yang dipilih melebihi %s baris. Persempit rentang tanggal terlebih dahulu.',
            number_format(self::EXPORT_LIMIT, 0, ',', '.'),
        ));
    }
}
