<?php

namespace Database\Seeders;

use App\Enums\AlasanSelisih;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\OpnamePoster;
use Illuminate\Database\Seeder;

class StockOpnameSeeder extends Seeder
{
    /**
     * Dua contoh untuk pengembangan: satu opname yang sudah diposting pada
     * periode sebelumnya, dan satu draf yang masih berjalan.
     *
     * Yang selesai diposting lewat OpnamePoster supaya mutasi penyesuaiannya
     * konsisten dengan buku mutasi.
     */
    public function run(): void
    {
        $petugas = User::where('role', UserRole::PengelolaGudang)->first()
            ?? User::where('role', UserRole::Admin)->first();

        if (Item::aktif()->doesntExist() || $petugas === null) {
            $this->command?->warn('Butuh akun dan barang. Jalankan UserSeeder dan ItemSeeder terlebih dahulu.');

            return;
        }

        // Tiap opname mengambil barang segar sendiri. Koleksi yang dipakai ulang
        // akan basi setelah opname pertama diposting, sehingga stok tercatat pada
        // draf berikutnya tidak lagi sesuai kenyataan.
        $this->opnameSelesai($petugas);
        $this->opnameDraf($petugas);
    }

    /**
     * Opname triwulan lalu: sebagian barang berselisih dan sudah diposting.
     */
    private function opnameSelesai(User $petugas): void
    {
        $barang = Item::aktif()->orderBy('item_code')->get();

        $opname = StockOpname::create([
            'opname_date' => now()->subDays(35),
            'user_id' => $petugas->user_id,
            'status' => OpnameStatus::Draf,
            'period_type' => PeriodeOpname::TriwulanII,
            'period_year' => now()->year,
            'notes' => 'Opname rutin triwulanan bersama Subbagian Umum dan TU.',
        ]);

        // Selisih hanya pada tiga barang pertama; sisanya cocok.
        $selisih = [
            0 => [-2, AlasanSelisih::BarangRusak, 'Dua rim basah terkena rembesan atap gudang.'],
            1 => [-1, AlasanSelisih::BarangHilang, 'Tidak ditemukan saat pencacahan ulang.'],
            2 => [3, AlasanSelisih::TransaksiBelumDibukukan, 'Penerimaan hibah belum sempat dicatat.'],
        ];

        foreach ($barang as $i => $item) {
            [$delta, $alasan, $keterangan] = $selisih[$i] ?? [0, null, null];

            $stokFisik = max(0, $item->stock_current + $delta);

            $opname->details()->create([
                'item_id' => $item->item_id,
                'stock_stored' => $item->stock_current,
                'stock_real' => $stokFisik,
                'difference' => $stokFisik - $item->stock_current,
                'counted_at' => now()->subDays(35),
                'adjustment_reason' => $stokFisik === $item->stock_current ? null : $alasan,
                'adjustment_note' => $stokFisik === $item->stock_current ? null : $keterangan,
            ]);
        }

        OpnamePoster::post($opname, $petugas);

        $this->command?->info("Opname contoh diposting: {$opname->judul()}.");
    }

    /**
     * Opname triwulan berjalan: masih draf, baru sebagian dicacah.
     */
    private function opnameDraf(User $petugas): void
    {
        $barang = Item::aktif()->orderBy('item_code')->get();

        $opname = StockOpname::create([
            'opname_date' => now(),
            'user_id' => $petugas->user_id,
            'status' => OpnameStatus::Draf,
            'period_type' => PeriodeOpname::TriwulanIII,
            'period_year' => now()->year,
            'notes' => null,
        ]);

        foreach ($barang as $i => $item) {
            // Lima barang pertama sudah dicacah tanpa selisih, sisanya belum.
            $sudahDicacah = $i < 5;

            $opname->details()->create([
                'item_id' => $item->item_id,
                'stock_stored' => $item->stock_current,
                'stock_real' => $sudahDicacah ? $item->stock_current : null,
                'difference' => $sudahDicacah ? 0 : null,
                'counted_at' => $sudahDicacah ? now() : null,
            ]);
        }

        $this->command?->info("Opname contoh berstatus draf: {$opname->judul()} (5 dari {$barang->count()} barang dicacah).");
    }
}
