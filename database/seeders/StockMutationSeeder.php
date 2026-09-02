<?php

namespace Database\Seeders;

use App\Enums\MutationType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Item;
use App\Models\User;
use App\Support\StockLedger;
use Illuminate\Database\Seeder;

class StockMutationSeeder extends Seeder
{
    /**
     * Contoh pergerakan stok selama 30 hari terakhir untuk pengembangan.
     *
     * Semuanya lewat StockLedger sehingga stock_after dan stock_current tetap
     * konsisten dengan buku mutasi.
     */
    public function run(): void
    {
        $petugas = User::where('role', UserRole::PengelolaGudang)->first()
            ?? User::where('role', UserRole::Admin)->first();

        $items = Item::aktif()->get();

        if ($items->isEmpty()) {
            $this->command?->warn('Belum ada barang. Jalankan ItemSeeder terlebih dahulu.');

            return;
        }

        $dicatat = 0;
        $dilewati = 0;

        foreach ($items as $index => $item) {
            foreach ($this->skenario($index) as [$type, $amount, $hariLalu, $refNumber, $notes]) {
                try {
                    StockLedger::record(
                        item: $item,
                        type: $type,
                        amount: $amount,
                        recordedBy: $petugas,
                        notes: $notes,
                        referenceType: $this->referenceTypeFor($type),
                        referenceNumber: $refNumber,
                        date: now()->subDays($hariLalu),
                    );

                    $dicatat++;
                } catch (InsufficientStockException) {
                    // Stok contoh tidak selalu cukup untuk tiap skenario; lewati saja.
                    $dilewati++;
                }
            }
        }

        $this->command?->info("Mutasi contoh dibuat: {$dicatat} baris ({$dilewati} dilewati karena stok tidak cukup).");
    }

    /**
     * Rangkaian mutasi per barang. Variasi ditentukan indeks barang supaya data
     * contoh tidak seragam tetapi tetap dapat diulang.
     *
     * @return array<int, array{0: MutationType, 1: int, 2: int, 3: ?string, 4: ?string}>
     */
    private function skenario(int $index): array
    {
        $seri = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

        $dasar = [
            [MutationType::Masuk, 24, 26, "PG01{$seri}", 'Penerimaan barang dari pengadaan triwulan.'],
            [MutationType::Keluar, 5, 21, "PR01{$seri}", 'Permintaan barang unit kerja.'],
            [MutationType::Keluar, 3, 12, 'PR02'.$seri, 'Permintaan barang unit kerja.'],
        ];

        // Setiap barang ketiga mendapat satu mutasi manual sebagai contoh.
        $manual = match ($index % 4) {
            0 => [[MutationType::BarangRusak, 2, 8, null, 'Kemasan rusak saat penyimpanan.']],
            1 => [[MutationType::HibahMasuk, 10, 9, null, 'Hibah dari kantor pusat.']],
            2 => [[MutationType::ReturDariUnit, 4, 5, null, 'Dikembalikan unit kerja karena kelebihan.']],
            default => [[MutationType::Kadaluarsa, 1, 3, null, 'Melewati masa pakai.']],
        };

        return [...$dasar, ...$manual];
    }

    private function referenceTypeFor(MutationType $type): ?string
    {
        return match ($type) {
            MutationType::Masuk => 'pengadaan',
            MutationType::Keluar => 'permintaan',
            default => null,
        };
    }
}
