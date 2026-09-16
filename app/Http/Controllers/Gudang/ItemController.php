<?php

namespace App\Http\Controllers\Gudang;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ItemProposalStatus;
use App\Enums\MutationType;
use App\Enums\SatuanBarang;
use App\Enums\StockStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gudang\StoreItemRequest;
use App\Http\Requests\Gudang\UpdateItemRequest;
use App\Models\Item;
use App\Models\ItemProposal;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use App\Support\Notifier;
use App\Support\ProcurementDraftScanner;
use App\Support\StockLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Master data barang persediaan.
 *
 * Akses dibatasi gate `kelola-gudang` pada rute: Pengelola Gudang dan Admin
 * (Admin lolos lewat Gate::before).
 */
class ItemController extends Controller
{
    private const PER_PAGE = 10;

    private const MUTATION_PER_PAGE = 15;

    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q', ''));
        $unit = (string) $request->query('unit', '');
        $stock = (string) $request->query('stock', '');
        $status = (string) $request->query('status', '');

        $items = Item::query()
            ->when($keyword !== '', fn ($query) => $query->search($keyword))
            ->when(SatuanBarang::tryFrom($unit), fn ($query, $satuan) => $query->where('unit', $satuan))
            ->when(StockStatus::tryFrom($stock), fn ($query, $stockStatus) => $query->stockStatus($stockStatus))
            ->when($status === 'aktif', fn ($query) => $query->where('is_active', true))
            ->when($status === 'arsip', fn ($query) => $query->where('is_active', false))
            ->orderBy('item_code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.items.index', [
            'title' => 'Master Barang',
            'items' => $items,
            'keyword' => $keyword,
            'unit' => $unit,
            'stock' => $stock,
            'status' => $status,
            'summary' => [
                'total' => Item::count(),
                'aktif' => Item::where('is_active', true)->count(),
                'menipis' => Item::aktif()->stockStatus(StockStatus::Menipis)->count(),
                'habis' => Item::aktif()->stockStatus(StockStatus::Habis)->count(),
            ],
        ]);
    }

    /**
     * Formulir barang baru, dua pintu masuk.
     *
     * Tanpa query — tombol "Tambah Barang" biasa.
     *
     * Dengan `?item_proposal_id=` — tombol "Setujui & Buat Barang" pada halaman
     * usulan barang: nama dan satuannya terisi dari usulan, sementara kode
     * barang serta batas minimum/maksimum tetap diisi Pengelola Gudang karena
     * hanya dia yang mengenal penamaan bakunya. Menyimpannya sekaligus menutup
     * usulan itu. Lihat ADR 0032.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $proposal = null;

        if ($request->query('item_proposal_id') !== null) {
            $proposal = ItemProposal::find((string) $request->query('item_proposal_id', ''));

            if ($proposal === null) {
                return redirect()
                    ->route('item-proposals.index')
                    ->with('error', 'Usulan barang tidak ditemukan.');
            }

            if ($proposal->sudahDiputuskan()) {
                return redirect()
                    ->route('item-proposals.show', $proposal)
                    ->with('error', "Usulan barang \"{$proposal->item_name}\" sudah diputuskan sebelumnya.");
            }
        }

        return view('pages.items.create', [
            'title' => 'Tambah Barang',
            'item' => new Item([
                'is_active' => true,
                'stock_min' => 0,
                'stock_max' => 0,
                'item_name' => $proposal?->item_name,
                'unit' => $proposal?->unit?->value,
            ]),
            'itemProposal' => $proposal,
        ]);
    }

    public function store(StoreItemRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $stokAwal = (int) $data['stock_awal'];
        unset($data['stock_awal'], $data['item_proposal_id']);
        $actor = $request->user();
        $proposal = $request->usulanBarang();

        $item = DB::transaction(function () use ($data, $stokAwal, $actor, $proposal) {
            $item = Item::create($data);

            // Stok awal masuk sebagai mutasi supaya buku mutasi cocok dengan
            // stock_current sejak hari pertama.
            if ($stokAwal > 0) {
                StockLedger::record(
                    item: $item,
                    type: MutationType::SaldoAwal,
                    amount: $stokAwal,
                    recordedBy: $actor,
                    notes: 'Stok pembuka saat barang didaftarkan.',
                );
            }

            // Mendaftarkan barangnya itulah persetujuan usulannya — satu
            // tindakan, satu kebenaran, di dalam transaksi yang sama supaya
            // tidak mungkin ada usulan berstatus Disetujui tanpa barang, atau
            // sebaliknya (ADR 0032).
            $proposal?->forceFill([
                'status' => ItemProposalStatus::Disetujui,
                'item_id' => $item->item_id,
                'decided_by' => $actor->user_id,
                'decided_at' => now(),
            ])->save();

            return $item;
        });

        // Satu aksi dengan dua makna: mendaftarkan barang baru, atau
        // menyetujui usulan dengan cara mendaftarkannya (ADR 0032). Modul dan
        // aksinya ikut bercabang supaya log dapat disaring sesuai maknanya.
        AuditLogger::record(
            user: $request->user(),
            activity: $proposal === null
                ? "Menambah barang: {$item->item_code} - {$item->item_name}"
                : "Menyetujui usulan barang \"{$proposal->item_name}\" dan mendaftarkannya sebagai {$item->item_code}",
            module: $proposal === null ? AuditModule::Barang : AuditModule::UsulanBarang,
            event: $proposal === null ? AuditEvent::Tambah : AuditEvent::Setuju,
            subject: $proposal ?? $item,
        );

        // Barang baru bisa langsung lahir dalam keadaan habis atau menipis.
        // Barang hasil usulan memang lahir berstok nol, jadi StokHabis dan draf
        // usulan otomatis akan langsung menyalakan diri — bukan kerusakan,
        // stoknya memang habis, dan keduanya melepas sendiri begitu barangnya
        // datang (ADR 0032).
        NotificationScanner::periksaBarang($item);
        ProcurementDraftScanner::perbaruiBarang($item);

        if ($proposal !== null) {
            NotificationScanner::catatUsulanBarangDiputuskan($proposal->fresh(['proposedBy', 'item']), $actor);

            return redirect()
                ->route('item-proposals.show', $proposal)
                ->with('status', "Usulan barang \"{$proposal->item_name}\" disetujui dan terdaftar sebagai {$item->item_code}.");
        }

        return redirect()
            ->route('items.index')
            ->with('status', "Barang {$item->item_code} berhasil ditambahkan.");
    }

    public function show(Item $item): View
    {
        return view('pages.items.show', [
            'title' => $item->item_name,
            'item' => $item,
            'mutations' => $item->stockMutations()
                ->with('user')
                ->latest('created_at')
                ->paginate(self::MUTATION_PER_PAGE),
        ]);
    }

    public function edit(Item $item): View
    {
        return view('pages.items.edit', [
            'title' => 'Ubah Barang',
            'item' => $item,
        ]);
    }

    public function update(UpdateItemRequest $request, Item $item): RedirectResponse
    {
        // validated() tidak pernah memuat stock_current, jadi stok aman.
        $item->fill($request->validated())->save();

        AuditLogger::record(
            user: $request->user(),
            activity: "Mengubah barang: {$item->item_code} - {$item->item_name}",
            module: AuditModule::Barang,
            event: AuditEvent::Ubah,
            subject: $item,
        );

        // Ambang batas ikut menentukan status stok, jadi mengubahnya bisa
        // memunculkan maupun menghilangkan peringatan tanpa stok bergerak.
        NotificationScanner::periksaBarang($item);
        ProcurementDraftScanner::perbaruiBarang($item);

        return redirect()
            ->route('items.index')
            ->with('status', "Barang {$item->item_code} berhasil diperbarui.");
    }

    /**
     * Arsipkan atau aktifkan kembali barang tanpa membuka formulir ubah.
     */
    public function toggleStatus(Request $request, Item $item): RedirectResponse
    {
        $item->forceFill(['is_active' => ! $item->is_active])->save();

        $state = $item->is_active ? 'mengaktifkan' : 'mengarsipkan';

        AuditLogger::record(
            user: $request->user(),
            activity: ucfirst($state)." barang: {$item->item_code}",
            module: AuditModule::Barang,
            event: AuditEvent::UbahStatus,
            subject: $item,
        );

        // Barang yang diarsipkan tidak lagi dilayani; peringatan stoknya ditutup.
        NotificationScanner::periksaBarang($item);
        ProcurementDraftScanner::perbaruiBarang($item);

        return back()->with('status', "Barang {$item->item_code} berhasil di{$state}.");
    }

    public function destroy(Request $request, Item $item): RedirectResponse
    {
        // Barang yang sudah bertransaksi harus tetap ada demi keutuhan riwayat.
        if ($blocking = $item->blockingRelations()) {
            return back()->with(
                'error',
                "Barang {$item->item_code} tidak dapat dihapus karena masih memiliki ".
                implode(', ', $blocking).'. Arsipkan barang ini sebagai gantinya.',
            );
        }

        $code = $item->item_code;

        // Notifikasi yang menunjuk barang ini hanya akan mengantar penggunanya
        // ke halaman yang sudah tidak ada.
        Notifier::hapusUntukSubjek($item);

        $item->delete();

        AuditLogger::record(
            user: $request->user(),
            activity: "Menghapus barang: {$code}",
            module: AuditModule::Barang,
            event: AuditEvent::Hapus,
        );

        return redirect()
            ->route('items.index')
            ->with('status', "Barang {$code} berhasil dihapus.");
    }
}
