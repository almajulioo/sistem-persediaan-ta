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
use Illuminate\Support\Str;

class ProcurementSeeder extends Seeder
{
    /**
     * Dua realisasi contoh.
     *
     * Yang pertama dibawa sampai penerimaan supaya modul Tahap 3 langsung
     * terlihat bekerja: stok bertambah lewat StockLedger sungguhan, bukan data
     * yang ditulis langsung ke tabel. Yang kedua sengaja berhenti di
     * `MenungguPersetujuan` supaya layar Kasubbag Umum dan TI punya antrean
     * berisi sejak data awal (ADR 0030).
     *
     * Usulan "Disetujui Penuh" (ATK-012) sengaja dibiarkan belum
     * direalisasikan — itulah yang mendemokan tombol "Realisasikan" pada
     * halaman usulan.
     *
     * Tanggal penerimaannya dimundurkan lima hari, melewati ambang tiga hari
     * pada NotificationScanner, supaya notifikasi keadaan BastBelumDiunggah
     * punya contoh nyata di data awal — berkas BAST-nya memang belum ada.
     */
    public function run(): void
    {
        $usulan = ProcurementRequest::where('status', ProcurementRequestStatus::DisetujuiMenungguRealisasi)
            ->with('details.item')
            ->orderBy('created_at')
            ->first();

        $supplier = Supplier::first();
        $pejabat = User::where('role', UserRole::PejabatPengadaan)->first();
        $petugasGudang = User::where('role', UserRole::PengelolaGudang)->first();
        $kasubbag = User::where('role', UserRole::KasubbagUmumTi)->first();

        if ($usulan === null || $supplier === null || $pejabat === null || $petugasGudang === null) {
            return;
        }

        // Harga per satuan hanya perkiraan untuk data contoh.
        $harga = ['ATK-003' => 15000, 'ATK-004' => 25000];

        $procurement = Procurement::create([
            // UUID acak eksplisit, bukan UUID v7 berurut bawaan HasUuids: kedua
            // realisasi di seeder ini lahir pada milidetik yang sama, sehingga
            // 8 karakter pertama `judul()` akan identik dan keduanya tampil
            // dengan nama yang sama persis di daftar. Lihat gotcha di CLAUDE.md.
            'procurement_id' => (string) Str::uuid(),
            'procurement_request_id' => $usulan->procurement_request_id,
            'supplier_id' => $supplier->supplier_id,
            'procurement_date' => now()->subDays(10),
            'status' => ProcurementStatus::MenungguPenerimaan,
            'user_id' => $pejabat->user_id,
        ]);

        // Dibuat langsung lewat model, jadi jejak persetujuannya ikut ditiru di
        // sini — tanpa ini realisasi yang sudah berjalan tampil seolah tidak
        // pernah melewati meja Kasubbag Umum dan TI (ADR 0030).
        $procurement->forceFill([
            'reviewed_at' => now()->subDays(9),
            'reviewed_by' => $kasubbag?->user_id,
            'review_note' => 'Harga sesuai pagu; lanjutkan pemesanan.',
        ])->save();

        // Dibuat langsung lewat model, bukan lewat ProcurementController::store(),
        // jadi transisi statusnya (ADR 0028) perlu ditiru manual di sini.
        $usulan->forceFill(['status' => ProcurementRequestStatus::DisetujuiSelesai])->save();

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
            receivedAt: now()->subDays(5),
            notes: 'Tinta warna dikirim kurang 5 botol dari pesanan; penyedia akan mengirim susulan.',
        );

        $this->antreanPersetujuan($supplier, $pejabat);

        $this->command?->info('Realisasi pengadaan contoh berhasil dibuat (1 sudah diterima sebagian, 1 menunggu persetujuan).');
    }

    /**
     * Realisasi kedua yang berhenti di `MenungguPersetujuan`, memakai usulan
     * yang memang disediakan ProcurementRequestSeeder untuk keperluan ini.
     *
     * Tanpa baris seperti ini, layar Kasubbag Umum dan TI kosong pada data awal
     * dan perannya sulit didemokan.
     */
    private function antreanPersetujuan(Supplier $supplier, User $pejabat): void
    {
        $usulan = ProcurementRequest::where('status', ProcurementRequestStatus::DisetujuiMenungguRealisasi)
            ->whereHas('details.item', fn ($query) => $query->where('item_code', 'ATK-005'))
            ->with('details')
            ->first();

        if ($usulan === null) {
            return;
        }

        $procurement = Procurement::create([
            'procurement_id' => (string) Str::uuid(),
            'procurement_request_id' => $usulan->procurement_request_id,
            'supplier_id' => $supplier->supplier_id,
            'procurement_date' => now()->subDay(),
            'status' => ProcurementStatus::MenungguPersetujuan,
            'user_id' => $pejabat->user_id,
        ]);

        // Transisi ADR 0028 ditiru manual, sama seperti realisasi pertama:
        // baris ini tidak lewat ProcurementController::store().
        $usulan->forceFill(['status' => ProcurementRequestStatus::DisetujuiSelesai])->save();

        foreach ($usulan->details as $detail) {
            $procurement->details()->create([
                'item_id' => $detail->item_id,
                'amount' => $detail->amount_granted,
                'unit_price' => 32000,
            ]);
        }
    }
}
