<?php

namespace App\Http\Controllers\Gudang;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\OpnameStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OpnameTidakSiapException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gudang\StoreOpnameRequest;
use App\Http\Requests\Gudang\UpdateOpnameCountRequest;
use App\Models\Item;
use App\Models\StockOpname;
use App\Support\AuditLogger;
use App\Support\OpnamePoster;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Stok opname: pencacahan fisik dan penyesuaian stok.
 *
 * Draf boleh disimpan sebagian, tetapi posting menuntut seluruh barang sudah
 * dicacah. Setelah diposting, dokumen bersifat final karena penyesuaiannya sudah
 * tercatat di buku mutasi yang immutable. Lihat ADR 0010.
 */
class StockOpnameController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $filters = [
            'period' => (string) $request->query('period', ''),
            'year' => (string) $request->query('year', ''),
            'status' => (string) $request->query('status', ''),
        ];

        return view('pages.opname.index', [
            'title' => 'Stok Opname',
            'opnames' => StockOpname::query()
                ->filter($filters)
                ->with(['user', 'details'])
                ->latest('opname_date')
                ->latest('created_at')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => [
                'total' => StockOpname::count(),
                'draf' => StockOpname::where('status', OpnameStatus::Draf)->count(),
                'selesai' => StockOpname::where('status', OpnameStatus::Selesai)->count(),
                'tahun_ini' => StockOpname::where('period_year', now()->year)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('pages.opname.create', [
            'title' => 'Mulai Stok Opname',
        ]);
    }

    public function store(StoreOpnameRequest $request): RedirectResponse
    {
        $barang = Item::aktif()->orderBy('item_code')->get();

        if ($barang->isEmpty()) {
            return back()->with('error', 'Belum ada barang aktif yang dapat dicacah.');
        }

        $opname = DB::transaction(function () use ($request, $barang) {
            $opname = StockOpname::create($request->validated() + [
                'user_id' => $request->user()->user_id,
                'status' => OpnameStatus::Draf,
            ]);

            // Stok tercatat di-snapshot saat draf dibuat. Barang yang
            // didaftarkan setelah ini tidak ikut — memang benar, karena opname
            // mencakup barang yang ada saat pencacahan dimulai.
            $opname->details()->createMany(
                $barang->map(fn (Item $item) => [
                    'item_id' => $item->item_id,
                    'stock_stored' => $item->stock_current,
                ])->all(),
            );

            return $opname;
        });

        AuditLogger::record(
            user: $request->user(),
            activity: "Membuat draf {$opname->judul()}",
            module: AuditModule::Opname,
            event: AuditEvent::Tambah,
            subject: $opname,
        );

        return redirect()
            ->route('opname.edit', $opname)
            ->with('status', "{$opname->judul()} dibuat dengan {$barang->count()} barang.");
    }

    public function show(StockOpname $opname): View
    {
        return view('pages.opname.show', [
            'title' => $opname->judul(),
            'opname' => $opname->load(['user', 'details.item']),
        ]);
    }

    public function edit(StockOpname $opname): View|RedirectResponse
    {
        if ($opname->isSelesai()) {
            return redirect()
                ->route('opname.show', $opname)
                ->with('error', 'Stok opname ini sudah diposting dan tidak dapat diubah.');
        }

        return view('pages.opname.edit', [
            'title' => $opname->judul(),
            'opname' => $opname->load(['details.item']),
        ]);
    }

    public function update(UpdateOpnameCountRequest $request, StockOpname $opname): RedirectResponse
    {
        if ($opname->isSelesai()) {
            return back()->with('error', 'Stok opname ini sudah diposting dan tidak dapat diubah.');
        }

        $details = $request->details();

        DB::transaction(function () use ($request, $details) {
            foreach ((array) $request->validated()['details'] as $id => $baris) {
                $detail = $details->get((string) $id);

                if ($detail === null) {
                    continue;
                }

                $stokReal = ($baris['stock_real'] ?? '') === '' || $baris['stock_real'] === null
                    ? null
                    : (int) $baris['stock_real'];

                $detail->forceFill([
                    'stock_real' => $stokReal,
                    'difference' => $stokReal === null ? null : $stokReal - $detail->stock_stored,
                    'counted_at' => $stokReal === null ? null : now(),
                    'adjustment_reason' => $baris['adjustment_reason'] ?? null,
                    'adjustment_note' => $baris['adjustment_note'] ?? null,
                ])->save();
            }
        });

        $sisa = $opname->details()->whereNull('stock_real')->count();
        $total = $opname->details()->count();

        // Angka hasil cacah fisik adalah dasar penyesuaian stok nanti, jadi
        // pengisiannya ikut ditelusuri — bukan hanya pembuatan dan pemostingan
        // drafnya. Kemajuan pencacahan disebut supaya pembaca log dapat
        // membedakan pengisian awal dari koreksi menjelang posting.
        AuditLogger::record(
            user: $request->user(),
            activity: sprintf(
                'Mengubah hasil cacah %s (%d dari %d barang tercacah)',
                $opname->judul(),
                $total - $sisa,
                $total,
            ),
            module: AuditModule::Opname,
            event: AuditEvent::Ubah,
            subject: $opname,
        );

        return redirect()
            ->route('opname.edit', $opname)
            ->with('status', $sisa === 0
                ? 'Hasil cacah tersimpan. Seluruh barang sudah dicacah dan siap diposting.'
                : "Hasil cacah tersimpan. Masih ada {$sisa} barang yang belum dicacah.");
    }

    /**
     * Terapkan penyesuaian ke stok dan kunci dokumen menjadi final.
     */
    public function post(Request $request, StockOpname $opname): RedirectResponse
    {
        try {
            OpnamePoster::post($opname, $request->user());
        } catch (OpnameTidakSiapException $e) {
            return back()->with('error', $e->getMessage());
        } catch (InsufficientStockException $e) {
            // Penyesuaian kurang bisa membuat stok minus bila banyak barang
            // keluar setelah pencacahan. Seluruh posting dibatalkan.
            return back()->with('error', $e->getMessage().' Posting dibatalkan; periksa kembali hasil cacah.');
        }

        return redirect()
            ->route('opname.show', $opname)
            ->with('status', "{$opname->judul()} berhasil diposting.");
    }

    public function destroy(Request $request, StockOpname $opname): RedirectResponse
    {
        // Opname selesai sudah menghasilkan mutasi yang immutable, sehingga
        // dokumennya pun tidak boleh hilang.
        if ($opname->isSelesai()) {
            return back()->with('error', 'Stok opname yang sudah diposting tidak dapat dihapus.');
        }

        $judul = $opname->judul();
        $opname->delete();

        AuditLogger::record(
            user: $request->user(),
            activity: "Menghapus draf {$judul}",
            module: AuditModule::Opname,
            event: AuditEvent::Hapus,
        );

        return redirect()
            ->route('opname.index')
            ->with('status', "Draf {$judul} berhasil dihapus.");
    }

    public function exportPdf(Request $request, StockOpname $opname): Response
    {
        $pdf = Pdf::loadView('pdf.laporan-opname', [
            'opname' => $opname->load(['user', 'details.item']),
            'dicetakOleh' => $request->user()->fullname,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('laporan-opname-'.$opname->period_year.'-'.$opname->period_type->value.'.pdf');
    }
}
