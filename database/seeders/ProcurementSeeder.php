<?php

namespace Database\Seeders;

use App\Enums\ProcurementRequestStatus;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\Procurement;
use App\Models\ProcurementRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ProcurementReceiptPoster;
use Illuminate\Database\Seeder;

class ProcurementSeeder extends Seeder
{
    /**
     * Satu realisasi contoh, dibawa sampai penerimaan supaya modul Tahap 3
     * langsung terlihat bekerja: stok bertambah lewat StockLedger sungguhan,
     * bukan data yang ditulis langsung ke tabel.
     *
     * Usulan "Disetujui Penuh" (ATK-012) sengaja dibiarkan belum
     * direalisasikan — itulah yang mendemokan tombol "Realisasikan" pada
     * halaman usulan.
     */
    public function run(): void
    {
        $usulan = ProcurementRequest::where('status', ProcurementRequestStatus::Disetujui)
            ->whereDoesntHave('procurement')
            ->with('details.item')
            ->orderBy('created_at')
            ->first();

        $supplier = Supplier::first();
        $pejabat = User::where('role', UserRole::PejabatPengadaan)->first();
        $petugasGudang = User::where('role', UserRole::PengelolaGudang)->first();

        if ($usulan === null || $supplier === null || $pejabat === null || $petugasGudang === null) {
            return;
        }

        // Harga per satuan hanya perkiraan untuk data contoh.
        $harga = ['ATK-003' => 15000, 'ATK-004' => 25000];

        $procurement = Procurement::create([
            'procurement_request_id' => $usulan->procurement_request_id,
            'supplier_id' => $supplier->supplier_id,
            'procurement_date' => now()->subDays(3),
            'status' => ProcurementStatus::MenungguPenerimaan,
            'user_id' => $pejabat->user_id,
        ]);

        foreach ($usulan->details as $detail) {
            $procurement->details()->create([
                'item_id' => $detail->item_id,
                'amount' => $detail->amount_granted,
                'unit_price' => $harga[$detail->item?->item_code] ?? 10000,
            ]);
        }

        // Salah satu barang datang kurang dari pesanan, supaya
        // ProcurementDetail::kurangKirim() punya contoh nyata untuk ditampilkan.
        $diterima = [];

        foreach ($procurement->details as $line) {
            $diterima[$line->procurement_detail_id] = $line->item?->item_code === 'ATK-004'
                ? max(0, $line->amount - 5)
                : $line->amount;
        }

        ProcurementReceiptPoster::post(
            procurement: $procurement,
            diterima: $diterima,
            actor: $petugasGudang,
            bastNumber: '001/BAST/'.now()->year,
            receivedAt: now()->subDay(),
            notes: 'Tinta warna dikirim kurang 5 botol dari pesanan; penyedia akan mengirim susulan.',
        );

        $this->command?->info('Realisasi pengadaan contoh berhasil dibuat (1 realisasi, sudah diterima sebagian).');
    }
}
