<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            ItemSeeder::class,
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
