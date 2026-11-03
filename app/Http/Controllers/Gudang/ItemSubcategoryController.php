<?php

namespace App\Http\Controllers\Gudang;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\KategoriBarang;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gudang\StoreItemSubcategoryRequest;
use App\Http\Requests\Gudang\UpdateItemSubcategoryRequest;
use App\Models\ItemSubcategory;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Sub kategori barang persediaan (ADR 0043).
 *
 * Rute ubah di bawah gate `kelola-gudang`: Pengelola Gudang dan Admin (Admin
 * lolos lewat Gate::before). Daftar di bawah `akses-gudang`, yang juga
 * meloloskan Kasubbag Umum dan TI baca-saja (ADR 0041).
 */
class ItemSubcategoryController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');

        $subcategories = ItemSubcategory::query()
            ->withCount('items')
            ->when($keyword !== '', fn ($query) => $query->search($keyword))
            ->when(KategoriBarang::tryFrom($category), fn ($query, $kategori) => $query->where('category', $kategori))
            ->orderBy('subcategory_code')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $perKategori = ItemSubcategory::query()
            ->select('category', DB::raw('count(*) as total'))
            ->groupBy('category')
            ->pluck('total', 'category');

        return view('pages.item-subcategories.index', [
            'title' => 'Sub Kategori Barang',
            'subcategories' => $subcategories,
            'keyword' => $keyword,
            'category' => $category,
            'summary' => [
                'total' => $perKategori->sum(),
                KategoriBarang::ATK->value => (int) $perKategori->get(KategoriBarang::ATK->value, 0),
                KategoriBarang::ARTK->value => (int) $perKategori->get(KategoriBarang::ARTK->value, 0),
            ],
        ]);
    }

    public function create(): View
    {
        return view('pages.item-subcategories.create', [
            'title' => 'Tambah Sub Kategori',
            'subcategory' => new ItemSubcategory,
        ]);
    }

    public function store(StoreItemSubcategoryRequest $request): RedirectResponse
    {
        $subcategory = ItemSubcategory::create($request->validated());

        AuditLogger::record(
            user: $request->user(),
            activity: "Menambah sub kategori barang: {$subcategory->subcategory_code} - {$subcategory->subcategory_name}",
            module: AuditModule::SubKategoriBarang,
            event: AuditEvent::Tambah,
            subject: $subcategory,
        );

        return redirect()
            ->route('item-subcategories.index')
            ->with('status', "Sub kategori {$subcategory->subcategory_name} berhasil ditambahkan.");
    }

    public function edit(ItemSubcategory $subcategory): View
    {
        return view('pages.item-subcategories.edit', [
            'title' => 'Ubah Sub Kategori',
            'subcategory' => $subcategory->loadCount('items'),
        ]);
    }

    public function update(UpdateItemSubcategoryRequest $request, ItemSubcategory $subcategory): RedirectResponse
    {
        // Mengubah kategori ikut memindahkan kategori seluruh barangnya —
        // dikerjakan hook `updated` pada model, di dalam transaksi yang sama.
        DB::transaction(fn () => $subcategory->fill($request->validated())->save());

        AuditLogger::record(
            user: $request->user(),
            activity: "Mengubah sub kategori barang: {$subcategory->subcategory_code} - {$subcategory->subcategory_name}",
            module: AuditModule::SubKategoriBarang,
            event: AuditEvent::Ubah,
            subject: $subcategory,
        );

        return redirect()
            ->route('item-subcategories.index')
            ->with('status', "Sub kategori {$subcategory->subcategory_name} berhasil diperbarui.");
    }

    public function destroy(Request $request, ItemSubcategory $subcategory): RedirectResponse
    {
        if ($blocking = $subcategory->blockingRelations()) {
            return back()->with(
                'error',
                "Sub kategori {$subcategory->subcategory_name} tidak dapat dihapus karena masih dipakai ".
                implode(', ', $blocking).'.',
            );
        }

        $name = $subcategory->subcategory_name;
        $code = $subcategory->subcategory_code;

        $subcategory->delete();

        AuditLogger::record(
            user: $request->user(),
            activity: "Menghapus sub kategori barang: {$code} - {$name}",
            module: AuditModule::SubKategoriBarang,
            event: AuditEvent::Hapus,
        );

        return redirect()
            ->route('item-subcategories.index')
            ->with('status', "Sub kategori {$name} berhasil dihapus.");
    }
}
