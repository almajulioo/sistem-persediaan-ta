<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\ProcurementStatus;
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
            ->withCount($this->cacahPengadaan())
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
            // Angka ringkasnya memakai definisi yang sama dengan daftar, tetapi
            // tabel di bawahnya tetap memuat seluruh baris beserta badge
            // statusnya: ditampilkan, tidak dihitung (ADR 0026).
            'supplier' => $supplier->loadCount($this->cacahPengadaan()),
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
        //
        // Realisasi yang dibatalkan pun ikut menghalangi — `supplier_id`
        // memakai restrictOnDelete, jadi barisnya tetap menunjuk penyedia ini.
        // Disebut eksplisit di pesannya supaya "0 pengadaan tetapi tidak bisa
        // dihapus" tidak terbaca sebagai bug (ADR 0026).
        if ($blocking = $supplier->blockingRelations()) {
            return back()->with(
                'error',
                "Penyedia {$supplier->supplier_name} tidak dapat dihapus karena masih memiliki ".
                implode(', ', $blocking).', termasuk yang sudah dibatalkan. Arsipkan penyedia ini sebagai gantinya.',
            );
        }

        $name = $supplier->supplier_name;

        $supplier->delete();

        AuditLogger::record($request->user(), "Menghapus penyedia: {$name}");

        return redirect()
            ->route('suppliers.index')
            ->with('status', "Penyedia {$name} berhasil dihapus.");
    }

    /**
     * Cacah pengadaan per penyedia, dipakai bersama daftar dan detail.
     *
     * Angka utama hanya menghitung pengadaan yang benar-benar berjalan;
     * realisasi yang dibatalkan dicacah terpisah, tidak pernah dilebur ke
     * dalamnya. Sebuah realisasi batal adalah dokumen yang pernah ada tetapi
     * tidak pernah menjadi transaksi, sehingga menghitungnya sebagai
     * "pengadaan ke sekian" pada penyedia itu menyesatkan. Lihat ADR 0026.
     *
     * Sejak ADR 0030 realisasi yang **ditolak** Kasubbag Umum dan TI ikut
     * dikeluarkan dari angka utama, dengan alasan yang sama. Cacahnya berdiri
     * sendiri, bukan dilebur ke dalam cacah pembatalan: penolakan tidak
     * terminal — dokumennya masih boleh diperbaiki lalu diajukan ulang —
     * sehingga menyebutnya "dibatalkan" sama menyesatkannya.
     *
     * @return array<string, \Closure>
     */
    private function cacahPengadaan(): array
    {
        return [
            'procurements as procurements_count' => fn ($query) => $query->whereNotIn('status', ProcurementStatus::takPernahJadiTransaksi()),
            'procurements as procurements_cancelled_count' => fn ($query) => $query->where('status', ProcurementStatus::Dibatalkan),
            'procurements as procurements_rejected_count' => fn ($query) => $query->where('status', ProcurementStatus::Ditolak),
        ];
    }
}
