<?php

namespace App\Http\Controllers\Pengadaan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\StoreSupplierRequest;
use App\Http\Requests\Pengadaan\UpdateSupplierRequest;
use App\Models\Supplier;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Data induk penyedia barang pengadaan.
 *
 * Akses dibatasi gate `kelola-pengadaan` pada rute: Pejabat Pengadaan dan
 * Admin (Admin lolos lewat Gate::before).
 */
class SupplierController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $suppliers = Supplier::query()
            ->withCount('procurements')
            ->when($keyword !== '', fn ($query) => $query->search($keyword))
            ->when($status === 'aktif', fn ($query) => $query->where('is_active', true))
            ->when($status === 'arsip', fn ($query) => $query->where('is_active', false))
            ->orderBy('supplier_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.suppliers.index', [
            'title' => 'Penyedia Barang',
            'suppliers' => $suppliers,
            'keyword' => $keyword,
            'status' => $status,
            'summary' => [
                'total' => Supplier::count(),
                'aktif' => Supplier::where('is_active', true)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('pages.suppliers.create', [
            'title' => 'Tambah Penyedia',
            'supplier' => new Supplier(['is_active' => true]),
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $supplier = Supplier::create($request->validated());

        AuditLogger::record($request->user(), "Menambah penyedia: {$supplier->supplier_name}");

        return redirect()
            ->route('suppliers.index')
            ->with('status', "Penyedia {$supplier->supplier_name} berhasil ditambahkan.");
    }

    public function show(Supplier $supplier): View
    {
        return view('pages.suppliers.show', [
            'title' => $supplier->supplier_name,
            'supplier' => $supplier,
            'procurements' => $supplier->procurements()
                ->with('procurementRequest')
                ->latest('procurement_date')
                ->paginate(self::PER_PAGE),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('pages.suppliers.edit', [
            'title' => 'Ubah Penyedia',
            'supplier' => $supplier,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->fill($request->validated())->save();

        AuditLogger::record($request->user(), "Mengubah penyedia: {$supplier->supplier_name}");

        return redirect()
            ->route('suppliers.index')
            ->with('status', "Penyedia {$supplier->supplier_name} berhasil diperbarui.");
    }

    /**
     * Arsipkan atau aktifkan kembali penyedia tanpa membuka formulir ubah.
     */
    public function toggleStatus(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->forceFill(['is_active' => ! $supplier->is_active])->save();

        $state = $supplier->is_active ? 'mengaktifkan' : 'mengarsipkan';

        AuditLogger::record($request->user(), ucfirst($state)." penyedia: {$supplier->supplier_name}");

        return back()->with('status', "Penyedia {$supplier->supplier_name} berhasil di{$state}.");
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        // Penyedia yang sudah dipakai realisasi harus tetap ada demi keutuhan
        // riwayat dan BAST yang sudah terbit atas namanya.
        if ($blocking = $supplier->blockingRelations()) {
            return back()->with(
                'error',
                "Penyedia {$supplier->supplier_name} tidak dapat dihapus karena masih memiliki ".
                implode(', ', $blocking).'. Arsipkan penyedia ini sebagai gantinya.',
            );
        }

        $name = $supplier->supplier_name;

        $supplier->delete();

        AuditLogger::record($request->user(), "Menghapus penyedia: {$name}");

        return redirect()
            ->route('suppliers.index')
            ->with('status', "Penyedia {$name} berhasil dihapus.");
    }
}
