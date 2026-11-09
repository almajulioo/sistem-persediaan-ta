<?php

namespace App\Http\Controllers\Gudang;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\OpnameStatus;
use App\Exceptions\DrafOpnameMasihAdaException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OpnameTidakSiapException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gudang\StoreOpnameRequest;
use App\Http\Requests\Gudang\UpdateOpnameCountRequest;
use App\Models\Item;
use App\Models\OpnameDetail;
use App\Models\StockMutation;
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
 * Opname berkala wajib mengisi setiap barang; hanya opname insidental yang
 * barang kosongnya mengikuti stok sistem saat diposting (ADR 0056, 0057).
 * Hasil cacah dibandingkan dengan stok sistem pada waktu barang dihitung,
 * yang diisi petugas dari lembar cacahnya (ADR 0057). Hanya satu draf boleh
 * berjalan dalam satu waktu. Setelah diposting, dokumen bersifat final karena
 * penyesuaiannya sudah tercatat di buku mutasi yang immutable. Lihat ADR 0010.
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
            'drafAktif' => $this->drafAktif(),
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
            'drafAktif' => $this->drafAktif(),
        ]);
    }

    public function store(StoreOpnameRequest $request): RedirectResponse
    {
        $barang = Item::aktif()->orderBy('item_code')->get();

        if ($barang->isEmpty()) {
            return back()->with('error', 'Belum ada barang aktif yang dapat dicacah.');
        }

        try {
            [$opname, $ulang] = KirimanFormulir::sekali($request, function () use ($request, $barang) {
                // Satu draf dalam satu waktu (ADR 0057). Diperiksa di dalam
                // transaksi, sesudah pemeriksaan token, supaya formulir yang
                // terkirim dua kali tetap diarahkan ke draf yang baru saja
                // dibuatnya, bukan ditolak.
                if ($draf = $this->drafAktif()) {
                    throw new DrafOpnameMasihAdaException($draf);
                }

                $opname = StockOpname::create($request->validated() + [
                    'user_id' => $request->user()->user_id,
                    'status' => OpnameStatus::Draf,
                ]);

                // Barang yang didaftarkan setelah ini tidak ikut — memang
                // benar, karena opname mencakup barang yang ada saat
                // pencacahan dimulai. `stock_stored` di sini hanya nilai
                // awal: ia diganti stok sistem pada waktu barang dihitung saat
                // hasil cacahnya disimpan (ADR 0057).
                $opname->details()->createMany(
                    $barang->map(fn (Item $item) => [
                        'item_id' => $item->item_id,
                        'stock_stored' => $item->stock_current,
                    ])->all(),
                );

                return $opname;
            });
        } catch (DrafOpnameMasihAdaException $e) {
            return redirect()
                ->route('opname.edit', $e->draf)
                ->with('error', $e->getMessage().' Posting atau hapus draf ini sebelum memulai opname baru.');
        }

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
            'mutasi' => $this->mutasiSejakTanggalCacah($opname),
        ]);
    }

    /**
     * Simpan hasil cacah, lalu posting bila `aksi=posting`.
     *
     * Posting lewat sini, bukan rute tersendiri, supaya isian di layar yang
     * belum disimpan ikut terposting (ADR 0056).
     */
    public function update(UpdateOpnameCountRequest $request, StockOpname $opname): RedirectResponse
    {
        if ($opname->isSelesai()) {
            return back()->with('error', 'Stok opname ini sudah diposting dan tidak dapat diubah.');
        }

        // Dibaca sebelum menyimpan: rincian di request adalah model yang sama
        // dengan yang diubah simpanHasilCacah(), jadi sesudahnya tidak ada
        // lagi angka yang tampak berubah.
        $waktu = $request->angkaBerubah() === [] ? null : $request->waktuCacah();

        $berubah = $this->simpanHasilCacah($request);

        // Angka hasil cacah fisik adalah dasar penyesuaian stok nanti, jadi
        // pengisiannya ikut ditelusuri — bukan hanya pembuatan dan pemostingan
        // drafnya. Waktu cacah disebut karena ia menentukan pembanding tiap
        // baris (ADR 0057). Kiriman tanpa perubahan tidak dicatat.
        if ($berubah > 0) {
            AuditLogger::record(
                user: $request->user(),
                activity: sprintf(
                    'Mengubah hasil cacah %s (%d barang diubah%s, %d dari %d barang diisi)',
                    $opname->judul(),
                    $berubah,
                    $waktu === null ? '' : ', dihitung pada '.$waktu->translatedFormat('d M Y H:i'),
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
     * Baris yang angkanya berubah mendapat pembanding baru: stok sistem pada
     * waktu cacah, dihitung ulang di sini dengan baris barang terkunci supaya
     * stok kini dan mutasinya terbaca dari keadaan yang sama. Waktu cacah itu
     * pula yang menjadi `counted_at`-nya.
     *
     * @return int jumlah baris yang benar-benar berubah
     */
    private function simpanHasilCacah(UpdateOpnameCountRequest $request): int
    {
        $details = $request->details();
        $angkaBerubah = $request->angkaBerubah();
        $waktu = $request->waktuCacah();
        $berubah = 0;

        DB::transaction(function () use ($request, $details, $angkaBerubah, $waktu, &$berubah) {
            $pembanding = $request->pembanding(array_keys($angkaBerubah), kunci: true);

            foreach ((array) ($request->validated()['details'] ?? []) as $id => $baris) {
                $detail = $details->get((string) $id);

                if ($detail === null) {
                    continue;
                }

                $stokReal = ($baris['stock_real'] ?? null) === null || $baris['stock_real'] === ''
                    ? null
                    : (int) $baris['stock_real'];

                if ($stokReal !== $detail->stock_real) {
                    // Waktu cacah adalah waktu di lembar kertas untuk angka
                    // itu, bukan saat terakhir disimpan. Baris tanpa waktu
                    // cacah adalah baris yang tidak diisi petugas (ADR 0056).
                    $detail->counted_at = $stokReal === null ? null : $waktu;

                    if ($stokReal !== null) {
                        $detail->stock_stored = $pembanding[$detail->item_id];
                    }
                }

                $detail->stock_real = $stokReal;
                $detail->difference = $stokReal === null ? null : $stokReal - $detail->stock_stored;

                // Alasan hanya bermakna pada baris berselisih. Baris yang
                // kembali cocok atau dikosongkan membuang sisa isiannya.
                $berselisih = $stokReal !== null && $detail->difference !== 0;

                $detail->adjustment_reason = $berselisih ? ($baris['adjustment_reason'] ?? null) : null;
                $detail->adjustment_note = $berselisih ? ($baris['adjustment_note'] ?? null) : null;

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

    /**
     * Draf yang masih berjalan, bila ada — paling lama satu (ADR 0057).
     */
    private function drafAktif(): ?StockOpname
    {
        return StockOpname::query()
            ->where('status', OpnameStatus::Draf)
            ->oldest('created_at')
            ->oldest('opname_id')
            ->first();
    }

    /**
     * Mutasi barang-barang opname ini sejak pukul 00:00 tanggal cacah — batas
     * paling awal "Dihitung pada" — supaya layar pencacahan dapat menghitung
     * stok sistem pada waktu mana pun di rentang itu tanpa bolak-balik ke
     * server. Waktu ditulis dalam zona waktu aplikasi dengan format yang
     * sejajar nilai isian `datetime-local`, sehingga cukup dibandingkan
     * sebagai teks.
     *
     * @return array<string, list<array{0: string, 1: int}>> opname_detail_id => [waktu, jumlah]
     */
    private function mutasiSejakTanggalCacah(StockOpname $opname): array
    {
        $rincian = $opname->details->pluck('opname_detail_id', 'item_id');

        return StockMutation::query()
            ->whereIn('item_id', OpnameDetail::query()->select('item_id')->where('opname_id', $opname->opname_id))
            ->where('created_at', '>=', $opname->opname_date->copy()->startOfDay())
            ->orderBy('created_at')
            ->get(['item_id', 'amount', 'created_at'])
            ->groupBy('item_id')
            ->mapWithKeys(fn ($mutasi, $itemId) => [
                $rincian[$itemId] => $mutasi
                    ->map(fn (StockMutation $m) => [$m->created_at->format('Y-m-d\TH:i:s'), (int) $m->amount])
                    ->values()
                    ->all(),
            ])
            ->all();
    }
}
