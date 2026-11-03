<?php

namespace Database\Seeders;

use App\Enums\KategoriBarang;
use App\Models\ItemSubcategory;
use Illuminate\Database\Seeder;

class ItemSubcategorySeeder extends Seeder
{
    /**
     * Sub kategori barang kantor dari data/kategori_barang.csv (ADR 0043).
     * Idempoten: sub kategori yang kodenya sudah ada tidak disentuh.
     */
    public function run(): void
    {
        $handle = fopen(database_path('seeders/data/kategori_barang.csv'), 'r');
        $kolom = array_map('trim', fgetcsv($handle, escape: ''));
        $dibuat = 0;

        while (($data = fgetcsv($handle, escape: '')) !== false) {
            if ($data === [null]) {
                continue;
            }

            $baris = array_combine($kolom, array_map('trim', $data));

            $subcategory = ItemSubcategory::firstOrCreate(
                ['subcategory_code' => $baris['subcategory_code']],
                [
                    'subcategory_name' => $baris['subcategory_name'],
                    'category' => KategoriBarang::from($baris['category_code']),
                ],
            );

            if ($subcategory->wasRecentlyCreated) {
                $dibuat++;
            }
        }

        fclose($handle);

        $this->command?->info("Sub kategori barang dibuat: {$dibuat}.");
    }
}
