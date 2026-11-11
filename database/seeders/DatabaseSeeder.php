<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Data dasar sistem: akun, sub kategori, dan master barang beserta saldo
     * awalnya. Tidak ada data transaksi contoh di sini; untuk itu jalankan
     * DataContohSeeder secara terpisah.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            ItemSubcategorySeeder::class,
            ItemSeeder::class,
        ]);
    }
}
