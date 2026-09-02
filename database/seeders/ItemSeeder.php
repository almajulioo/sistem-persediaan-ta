<?php

namespace Database\Seeders;

use App\Enums\MutationType;
use App\Enums\SatuanBarang;
use App\Models\Item;
use App\Support\StockLedger;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    /**
     * Contoh barang persediaan ATK untuk pengembangan.
     *
     * Stok awal ditulis lewat StockLedger, bukan langsung ke kolom
     * stock_current, supaya buku mutasi tetap konsisten sejak awal.
     */
    public function run(): void
    {
        $items = [
            ['ATK-001', 'Kertas HVS A4 70 gram', SatuanBarang::Rim, 120, 20, 300],
            ['ATK-002', 'Kertas HVS F4 70 gram', SatuanBarang::Rim, 45, 15, 200],
            ['ATK-003', 'Tinta Printer Hitam', SatuanBarang::Botol, 8, 10, 50],
            ['ATK-004', 'Tinta Printer Warna', SatuanBarang::Botol, 12, 10, 50],
            ['ATK-005', 'Pulpen Tinta Hitam', SatuanBarang::Lusin, 30, 10, 100],
            ['ATK-006', 'Pensil 2B', SatuanBarang::Lusin, 18, 5, 60],
            ['ATK-007', 'Map Snelhecter Plastik', SatuanBarang::Buah, 240, 50, 500],
            ['ATK-008', 'Ordner Folio', SatuanBarang::Buah, 65, 20, 150],
            ['ATK-009', 'Stapler Besar', SatuanBarang::Buah, 14, 5, 30],
            ['ATK-010', 'Isi Staples No. 10', SatuanBarang::Box, 0, 10, 80],
            ['ATK-011', 'Amplop Kop Dinas', SatuanBarang::Pak, 22, 10, 100],
            ['ATK-012', 'Spidol Whiteboard', SatuanBarang::Lusin, 9, 6, 40],
            ['ATK-013', 'Kertas Sticky Note', SatuanBarang::Pak, 35, 10, 90],
            ['RTK-001', 'Tisu Gulung', SatuanBarang::Dus, 16, 8, 60],
            ['RTK-002', 'Cairan Pembersih Lantai', SatuanBarang::Liter, 25, 10, 80],
        ];

        foreach ($items as [$code, $name, $unit, $stokAwal, $min, $max]) {
            $item = Item::firstOrCreate(
                ['item_code' => $code],
                [
                    'item_name' => $name,
                    'unit' => $unit,
                    'stock_min' => $min,
                    'stock_max' => $max,
                    'is_active' => true,
                ],
            );

            // Hanya isi saldo awal untuk barang yang baru dibuat.
            if ($item->wasRecentlyCreated && $stokAwal > 0) {
                StockLedger::record($item, MutationType::SaldoAwal, $stokAwal);
            }
        }

        $this->command?->info('Master barang contoh berhasil dibuat ('.count($items).' barang).');
    }
}
