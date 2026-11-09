<?php

namespace Database\Seeders;

use App\Enums\AlasanPengadaan;
use App\Enums\ProcurementRequestStatus;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Procurement;
use App\Models\ProcurementRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BastPengadaan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProcurementSeeder extends Seeder
{
    /**
     * Lima realisasi contoh.
     *
     * Yang pertama dibawa sampai penerimaan lewat alur BAST digital sungguhan
     * (ADR 0051) — hasil pemeriksaan, nomor, dan TTE kedua pihak — supaya QR
     * dan stok yang bertambah lewat StockLedger langsung terlihat, bukan data
     * yang ditulis langsung ke tabel. Yang kedua sengaja berhenti di
     * `MenungguPersetujuan` supaya layar Kasubbag Umum dan TI punya antrean
     * berisi sejak data awal (ADR 0030). Yang ketiga dibuat **tanpa usulan**
     * sama sekali, supaya pintu ADR 0031 beserta kartu Dasar Pengadaan-nya
     * punya contoh nyata. Yang keempat sudah disetujui tetapi berhenti di
     * `ProsesBelanja`, supaya tombol "Tandai Sudah Dibelanjakan" dan penagih
     * RealisasiBelumDibelanjakan punya contoh (ADR 0035).
     *
     * Usulan "Disetujui Penuh" (ATK-012) sengaja dibiarkan belum
     * direalisasikan — itulah yang mendemokan tombol "Realisasikan" pada
     * halaman usulan.
     *
     * Yang kelima sudah diperiksa dan ditandatangani Pihak Pertama saja,
     * supaya Pengelola Gudang punya TTE terakhir untuk didemokan.
     *
     * Barang contoh dari ProcurementRequestSeeder (`ATK-0xx`) tidak ada lagi di
     * katalog sungguhan sejak 24 Oktober 2026 (butir terbuka 13). Bila
     * usulannya kosong, kedua realisasi BAST memakai dua barang aktif pertama
     * dari katalog sebagai barang tambahan (ADR 0029): BAST tanpa barang tidak
     * dapat diperiksa maupun ditandatangani.
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
        //
        // Begitu pula fakta belanjanya (ADR 0035). Foto belanjanya sengaja
        // tidak ikut dibuat: seeder tidak menulis berkas apa pun ke disk
        // privat, sama seperti BAST bertanda tangan yang juga belum ada.
        $procurement->forceFill([
            'reviewed_at' => now()->subDays(9),
            'reviewed_by' => $kasubbag?->user_id,
            'review_note' => 'Harga sesuai pagu; lanjutkan pemesanan.',
            'purchased_at' => now()->subDays(7),
            'purchased_by' => $pejabat->user_id,
            'purchase_note' => 'Dipesan ke penyedia langganan; barang dikirim ke kantor.',
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

        $this->lengkapiBarang($procurement);

        // Salah satu barang datang kurang dari pesanan, supaya
        // ProcurementDetail::kurangKirim() punya contoh nyata untuk ditampilkan.
        $this->tandatanganiBast(
            procurement: $procurement,
            nomor: '001/UP_TUP/445237/'.now()->year,
            petugasGudang: $petugasGudang,
            penandaTangan: [$pejabat, $petugasGudang],
            catatan: 'Satu jenis barang dikirim kurang dari pesanan; penyedia akan mengirim susulan.',
        );

        $this->antreanPersetujuan($supplier, $pejabat);
        $this->realisasiLangsung($supplier, $pejabat);
        $this->prosesBelanja($supplier, $pejabat, $kasubbag);
        $this->menungguTtePihakKedua($supplier, $pejabat, $petugasGudang, $kasubbag);

        $this->command?->info('Realisasi pengadaan contoh berhasil dibuat (1 sudah diterima sebagian, 1 menunggu persetujuan, 1 langsung tanpa usulan, 1 dalam proses belanja, 1 menunggu TTE Pihak Kedua).');
    }

    /**
     * Realisasi yang barangnya sudah datang dan diperiksa, ber-TTE Pihak
     * Pertama, menunggu TTE Pengelola Gudang (ADR 0051).
     */
    private function menungguTtePihakKedua(Supplier $supplier, User $pejabat, User $petugasGudang, ?User $kasubbag): void
    {
        $procurement = Procurement::create([
            'procurement_id' => (string) Str::uuid(),
            'procurement_request_id' => null,
            'supplier_id' => $supplier->supplier_id,
            'procurement_date' => now()->subDays(4),
            'basis_reason' => AlasanPengadaan::BelanjaRutinNilaiKecil,
            'basis_note' => 'Kebutuhan rutin bulanan; dibeli langsung di penyedia langganan.',
            'status' => ProcurementStatus::MenungguPenerimaan,
            'user_id' => $pejabat->user_id,
        ]);

        $procurement->forceFill([
            'reviewed_at' => now()->subDays(3),
            'reviewed_by' => $kasubbag?->user_id,
            'review_note' => 'Setuju.',
            'purchased_at' => now()->subDays(2),
            'purchased_by' => $pejabat->user_id,
        ])->save();

        $this->lengkapiBarang($procurement);

        $this->tandatanganiBast(
            procurement: $procurement,
            nomor: '002/UP_TUP/445237/'.now()->year,
            petugasGudang: $petugasGudang,
            penandaTangan: [$pejabat],
        );
    }

    /**
     * Barang cadangan bila usulan contohnya tidak punya barang (butir terbuka
     * 13): dua barang aktif pertama dari katalog.
     */
    private function lengkapiBarang(Procurement $procurement): void
    {
        if ($procurement->details()->exists()) {
            return;
        }

        Item::query()
            ->where('is_active', true)
            ->orderBy('item_code')
            ->take(2)
            ->get()
            ->each(fn (Item $item, int $i) => $procurement->details()->create([
                'item_id' => $item->item_id,
                'amount' => 10 * ($i + 1),
                'unit_price' => 15000 + 10000 * $i,
            ]));
    }

    /**
     * Menjalankan alur BAST digital lewat `BastPengadaan`, sama seperti dari
     * layar: hasil pemeriksaan, nomor, lalu TTE. Barang terakhir datang kurang
     * lima dari pesanan.
     *
     * @param  list<User>  $penandaTangan
     */
    private function tandatanganiBast(
        Procurement $procurement,
        string $nomor,
        User $petugasGudang,
        array $penandaTangan,
        ?string $catatan = null,
    ): void {
        $procurement->load('details');

        if ($procurement->details->isEmpty()) {
            return;
        }

        $terakhir = $procurement->details->last()->procurement_detail_id;
        $jumlah = $procurement->details->mapWithKeys(fn ($line) => [
            $line->procurement_detail_id => $line->procurement_detail_id === $terakhir
                ? max(0, $line->amount - 5)
                : $line->amount,
        ])->all();

        BastPengadaan::catatPemeriksaan($procurement, $petugasGudang, now()->subDays(1), $jumlah, $catatan);
        BastPengadaan::isiNomor($procurement, $petugasGudang, $nomor);

        foreach ($penandaTangan as $user) {
            BastPengadaan::tandatangani($procurement, $user);
        }
    }

    /**
     * Realisasi langsung tanpa usulan, berhenti di `MenungguPersetujuan`
     * (ADR 0031).
     *
     * Dasar pengadaannya wajib terisi karena tidak ada dokumen usulan yang
     * dapat dinilai Kasubbag Umum dan TI — di sinilah `basis_reason` dan
     * `basis_note` menggantikannya.
     */
    private function realisasiLangsung(Supplier $supplier, User $pejabat): void
    {
        $barang = Item::where('item_code', 'ATK-002')->first();

        if ($barang === null) {
            return;
        }

        $procurement = Procurement::create([
            'procurement_id' => (string) Str::uuid(),
            'procurement_request_id' => null,
            'supplier_id' => $supplier->supplier_id,
            'procurement_date' => now()->subDays(2),
            'basis_reason' => AlasanPengadaan::KebutuhanMendesak,
            'basis_note' => 'Permintaan mendadak untuk rapat pembahasan LHP pekan ini; tidak dapat menunggu siklus usulan bulanan.',
            'status' => ProcurementStatus::MenungguPersetujuan,
            'user_id' => $pejabat->user_id,
        ]);

        $procurement->details()->create([
            'item_id' => $barang->item_id,
            'amount' => 10,
            'unit_price' => 18000,
        ]);
    }

    /**
     * Realisasi yang sudah disetujui tetapi belum dibelanjakan (ADR 0035).
     *
     * Persetujuannya dimundurkan delapan hari, melewati ambang tujuh hari pada
     * NotificationScanner, supaya penagih RealisasiBelumDibelanjakan punya
     * contoh nyata begitu `notifikasi:periksa` dijalankan — pola yang sama
     * dengan tanggal penerimaan realisasi pertama dan BastBelumDiunggah.
     */
    private function prosesBelanja(Supplier $supplier, User $pejabat, ?User $kasubbag): void
    {
        $barang = Item::where('item_code', 'ATK-010')->first();

        if ($barang === null) {
            return;
        }

        $procurement = Procurement::create([
            'procurement_id' => (string) Str::uuid(),
            'procurement_request_id' => null,
            'supplier_id' => $supplier->supplier_id,
            'procurement_date' => now()->subDays(9),
            'basis_reason' => AlasanPengadaan::BelanjaRutinNilaiKecil,
            'basis_note' => 'Isi staples habis dan dipakai setiap hari; nilainya kecil sehingga dibeli langsung di toko terdekat.',
            'status' => ProcurementStatus::ProsesBelanja,
            'user_id' => $pejabat->user_id,
        ]);

        $procurement->forceFill([
            'reviewed_at' => now()->subDays(8),
            'reviewed_by' => $kasubbag?->user_id,
            'review_note' => 'Setuju, belanjakan di toko terdekat.',
        ])->save();

        $procurement->details()->create([
            'item_id' => $barang->item_id,
            'amount' => 20,
            'unit_price' => 4500,
        ]);
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
