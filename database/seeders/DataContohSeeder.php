<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Seeder;

class DataContohSeeder extends Seeder
{
    /**
     * Data transaksi contoh untuk pengembangan dan pengujian manual:
     * penyedia, mutasi stok, stok opname, permohonan dan realisasi pengadaan,
     * usulan barang, dan log aktivitas.
     *
     * Ditumpuk di atas data dasar dari DatabaseSeeder, jadi jalankan itu lebih
     * dulu. UserSeeder sengaja tidak dipanggil di sini karena menimpa kata
     * sandi akun yang sudah ada.
     *
     *   php artisan db:seed --class=DataContohSeeder
     */
    public function run(): void
    {
        if (! User::exists() || ! Item::exists()) {
            $this->command?->warn('Data dasar belum ada. Jalankan `php artisan db:seed` terlebih dahulu.');

            return;
        }

        $this->call([
            SupplierSeeder::class,
            StockMutationSeeder::class,
            StockOpnameSeeder::class,
            ProcurementRequestSeeder::class,
            ProcurementSeeder::class,
            ItemProposalSeeder::class,
            // Paling akhir: barisnya menunjuk dokumen yang dibuat seeder di atas.
            AuditLogSeeder::class,
        ]);
    }
}
