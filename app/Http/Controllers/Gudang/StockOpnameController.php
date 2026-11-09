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
use App\Models\OpnameDetail;
use App\Models\StockOpname;
use App\Support\AuditLogger;
use App\Support\KirimanFormulir;
use App\Support\LaporanStokOpname;
use App\Support\OpnamePoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stok opname: pencacahan fisik dan penyesuaian stok.
 *
 * Petugas cukup mengisi barang yang jumlah fisiknya berbeda; barang yang
 * dikosongkan mengikuti stok sistem saat diposting (ADR 0056). Setelah
 * diposting, dokumen bersifat final karena penyesuaiannya sudah tercatat di
 * buku mutasi yang immutable. Lihat ADR 0010.
 */
class StockOpnameController extends Controller
{
    private const PER_PAGE = 10;

    /** Penyaring baris pada halaman hasil opname. */
    private const SARING_BARIS = ['diisi', 'berselisih', 'tidak_diisi'];

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

        [$opname, $ulang] = KirimanFormulir::sekali($request, function () use ($request, $barang) {
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

        if ($ulang) {
            return $opname === null
                ? redirect()->route('opname.index')
                : redirect()->route('opname.show', $opname)->with('status', "{$opname->judul()} sudah dibuat sebelumnya.");
        }

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

    public function show(Request $request, StockOpname $opname): View
    {
        $opname->load(['user', 'details.item']);

        $saring = in_array($request->query('baris'), self::SARING_BARIS, true)
            ? (string) $request->query('baris')
            : 'semua';

        return view('pages.opname.show', [
            'title' => $opname->judul(),
            'opname' => $opname,
            'saring' => $saring,
            'rincian' => $opname->details->filter(fn (OpnameDetail $detail) => match ($saring) {
                'diisi' => $detail->diisiPetugas(),
                'berselisih' => $detail->adaSelisih(),
                'tidak_diisi' => ! $detail->diisiPetugas(),
                default => true,
            }),
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

    /**
     * Simpan hasil cacah, lalu posting bila `aksi=posting`.
     *
     * Posting lewat sini, bukan rute tersendiri, supaya isian di layar yang
     * belum disimpan ikut terposting. Dulu posting memakai data tersimpan
     * saja; dengan barang kosong yang mengikuti stok sistem, isian yang lupa
     * disimpan akan diam-diam diposting sebagai "sesuai" (ADR 0056).
     */
    public function update(UpdateOpnameCountRequest $request, StockOpname $opname): RedirectResponse
    {
        if ($opname->isSelesai()) {
            return back()->with('error', 'Stok opname ini sudah diposting dan tidak dapat diubah.');
        }

        $berubah = $this->simpanHasilCacah($request);

        // Angka hasil cacah fisik adalah dasar penyesuaian stok nanti, jadi
        // pengisiannya ikut ditelusuri — bukan hanya pembuatan dan pemostingan
        // drafnya. Kemajuan pengisian disebut supaya pembaca log dapat
        // membedakan pengisian awal dari koreksi menjelang posting. Kiriman
        // tanpa perubahan tidak mengubah apa pun, jadi tidak dicatat.
        if ($berubah > 0) {
            AuditLogger::record(
                user: $request->user(),
                activity: sprintf(
                    'Mengubah hasil cacah %s (%d barang diubah, %d dari %d barang diisi)',
                    $opname->judul(),
                    $berubah,
                    $opname->details()->whereNotNull('counted_at')->count(),
                    $opname->details()->count(),
                ),
                module: AuditModule::Opname,
                event: AuditEvent::Ubah,
                subject: $opname,
            );
        }

        if ($request->mauPosting()) {
            return $this->posting($request, $opname);
        }

        return redirect()
            ->route('opname.edit', $opname)
            ->with('status', $berubah === 0 ? 'Tidak ada perubahan untuk disimpan.' : 'Hasil cacah tersimpan.');
    }

    /**
     * Terapkan penyesuaian ke stok dan kunci dokumen menjadi final.
     */
    private function posting(Request $request, StockOpname $opname): RedirectResponse
    {
        try {
            OpnamePoster::post($opname, $request->user());
        } catch (OpnameTidakSiapException $e) {
            return redirect()->route('opname.edit', $opname)->with('error', $e->getMessage());
        } catch (InsufficientStockException $e) {
            // Penyesuaian kurang bisa membuat stok minus bila banyak barang
            // keluar setelah pencacahan. Seluruh posting dibatalkan; isian
            // yang baru disimpan tetap ada di draf.
            return redirect()
                ->route('opname.edit', $opname)
                ->with('error', $e->getMessage().' Posting dibatalkan; periksa kembali hasil cacah.');
        }

        return redirect()
            ->route('opname.show', $opname)
            ->with('status', "{$opname->judul()} berhasil diposting.");
    }

    /**
     * Simpan baris yang dikirim; yang tidak dikirim tetap seperti tersimpan.
     *
     * @return int jumlah baris yang benar-benar berubah
     */
    private function simpanHasilCacah(UpdateOpnameCountRequest $request): int
    {
        $details = $request->details();
        $berubah = 0;

        DB::transaction(function () use ($request, $details, &$berubah) {
            foreach ((array) ($request->validated()['details'] ?? []) as $id => $baris) {
                $detail = $details->get((string) $id);

                if ($detail === null) {
                    continue;
                }

                $stokReal = ($baris['stock_real'] ?? null) === null || $baris['stock_real'] === ''
                    ? null
                    : (int) $baris['stock_real'];

                // Alasan hanya bermakna pada baris berselisih. Baris yang
                // kembali cocok atau dikosongkan membuang sisa isiannya.
                $berselisih = $stokReal !== null && $stokReal !== $detail->stock_stored;

                $detail->forceFill([
                    'stock_real' => $stokReal,
                    'difference' => $stokReal === null ? null : $stokReal - $detail->stock_stored,
                    'adjustment_reason' => $berselisih ? ($baris['adjustment_reason'] ?? null) : null,
                    'adjustment_note' => $berselisih ? ($baris['adjustment_note'] ?? null) : null,
                ]);

                // Waktu cacah adalah saat angka itu dimasukkan, bukan saat
                // terakhir disimpan. Baris tanpa waktu cacah adalah baris yang
                // tidak diisi petugas (ADR 0056).
                if ($detail->isDirty('stock_real')) {
                    $detail->counted_at = $stokReal === null ? null : now();
                }

                if ($detail->isDirty()) {
                    $detail->save();
                    $berubah++;
                }
            }
        });

        return $berubah;
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

    public function exportExcel(Request $request, StockOpname $opname): StreamedResponse
    {
        return LaporanStokOpname::unduh(
            LaporanStokOpname::laporan($opname->load(['user', 'details.item']), $request->user()),
            LaporanStokOpname::namaBerkas($opname),
        );
    }
}
