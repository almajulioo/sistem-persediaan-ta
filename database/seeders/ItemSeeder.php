<?php

namespace Database\Seeders;

use App\Enums\MutationType;
use App\Enums\SatuanBarang;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemSubcategory;
use App\Models\User;
use App\Support\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ItemSeeder extends Seeder
{
    private const TANGGAL_SALDO_AWAL = '2026-10-01';

    /**
     * Master barang dan saldo awal kantor, dari dua berkas di data/.
     *
     * Barang yang tidak tercantum di saldo awal tetap didaftarkan, tetapi
     * diarsipkan: stoknya nol dan kodenya harus ada agar tidak didaftarkan
     * ulang dengan kode lain. Stok awal ditulis lewat StockLedger, bukan
     * langsung ke stock_current, supaya buku mutasi konsisten sejak awal.
     *
     * Setiap barang menunjuk sub kategorinya lewat kolom `subcategory_code`;
     * sub kategorinya harus sudah ada (ItemSubcategorySeeder). Barang lama
     * yang belum punya sub kategori ikut diisi, tanpa menyentuh stoknya
     * (ADR 0043).
     */
    public function run(): void
    {
        $saldo = collect($this->bacaCsv('saldo_awal_2026_10_01.csv'))
            ->mapWithKeys(fn (array $baris) => [$baris['item_code'] => (int) $baris['qty']]);

        $subkategori = ItemSubcategory::pluck('subcategory_id', 'subcategory_code');
        $petugas = User::where('role', UserRole::PengelolaGudang)->first();
        $tanggal = CarbonImmutable::parse(self::TANGGAL_SALDO_AWAL);

        $dibuat = 0;
        $diarsipkan = 0;

        DB::transaction(function () use ($saldo, $subkategori, $petugas, $tanggal, &$dibuat, &$diarsipkan) {
            foreach ($this->bacaCsv('barang.csv') as $baris) {
                $stokAwal = $saldo->get($baris['item_code'], 0);
                $subcategoryId = $subkategori->get($baris['subcategory_code'])
                    ?? throw new RuntimeException("Sub kategori {$baris['subcategory_code']} untuk barang {$baris['item_code']} tidak ditemukan.");

                $item = Item::firstOrCreate(
                    ['item_code' => $baris['item_code']],
                    [
                        'item_name' => $baris['item_name'],
                        'subcategory_id' => $subcategoryId,
                        'unit' => SatuanBarang::from(strtolower($baris['unit'])),
                        'stock_min' => 1,
                        'stock_max' => 1000,
                        'is_active' => $stokAwal > 0,
                    ],
                );

                // Hanya isi saldo awal untuk barang yang baru dibuat.
                if (! $item->wasRecentlyCreated) {
                    if ($item->subcategory_id === null) {
                        $item->update(['subcategory_id' => $subcategoryId]);
                    }

                    continue;
                }

                $dibuat++;

                if ($stokAwal === 0) {
                    $diarsipkan++;

                    continue;
                }

                StockLedger::record(
                    item: $item,
                    type: MutationType::SaldoAwal,
                    amount: $stokAwal,
                    recordedBy: $petugas,
                    notes: 'Saldo awal per 1 Oktober 2026.',
                    date: $tanggal,
                );
            }
        });

        $this->command?->info("Master barang dibuat: {$dibuat} barang ({$diarsipkan} diarsipkan karena tanpa saldo awal).");
    }

    /**
     * @return list<array<string, string>> baris CSV berkunci nama kolom.
     */
    private function bacaCsv(string $berkas): array
    {
        $handle = fopen(database_path("seeders/data/{$berkas}"), 'r');
        // Nama kolom ikut di-trim: berkas ber-CRLF menyisakan carriage return
        // pada nama kolom terakhir.
        $kolom = array_map('trim', fgetcsv($handle, escape: ''));
        $baris = [];

        while (($data = fgetcsv($handle, escape: '')) !== false) {
            if ($data === [null]) {
                continue;
            }

            $baris[] = array_combine($kolom, array_map('trim', $data));
        }

        fclose($handle);

        return $baris;
    }
}
