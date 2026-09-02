<?php

namespace App\Http\Controllers\Gudang;

use App\Enums\MutationType;
use App\Enums\SatuanBarang;
use App\Enums\StockStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gudang\StoreItemRequest;
use App\Http\Requests\Gudang\UpdateItemRequest;
use App\Models\Item;
use App\Support\AuditLogger;
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

    public function create(): View
    {
        return view('pages.items.create', [
            'title' => 'Tambah Barang',
            'item' => new Item(['is_active' => true, 'stock_min' => 0, 'stock_max' => 0]),
        ]);
    }

    public function store(StoreItemRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $stokAwal = (int) $data['stock_awal'];
        unset($data['stock_awal']);

        $item = DB::transaction(function () use ($data, $stokAwal) {
            $item = Item::create($data);

            // Stok awal masuk sebagai mutasi supaya buku mutasi cocok dengan
            // stock_current sejak hari pertama.
            if ($stokAwal > 0) {
                StockLedger::record($item, MutationType::SaldoAwal, $stokAwal);
            }

            return $item;
        });

        AuditLogger::record($request->user(), "Menambah barang: {$item->item_code} - {$item->item_name}");

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
                ->latest('mutation_date')
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

        AuditLogger::record($request->user(), "Mengubah barang: {$item->item_code} - {$item->item_name}");

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

        AuditLogger::record($request->user(), ucfirst($state)." barang: {$item->item_code}");

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
        $item->delete();

        AuditLogger::record($request->user(), "Menghapus barang: {$code}");

        return redirect()
            ->route('items.index')
            ->with('status', "Barang {$code} berhasil dihapus.");
    }
}
