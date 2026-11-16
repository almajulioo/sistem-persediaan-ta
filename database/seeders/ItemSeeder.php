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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ItemSeeder extends Seeder
{
    /**
     * Saldo awal yang berlaku. Ikut dipakai perintah
     * `persediaan:ganti-saldo-awal`, yang mengganti saldo awal 1 Oktober di
     * server yang sudah terisi sebelum sistem dipakai (ADR 0058).
     */
    public const TANGGAL_SALDO_AWAL = '2026-11-01';

    public const BERKAS_SALDO_AWAL = 'saldo_awal_2026_11_01.csv';

    public const CATATAN_SALDO_AWAL = 'Saldo awal per 1 November 2026.';

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
        $saldo = self::saldoAwal();
        $subkategori = ItemSubcategory::pluck('subcategory_id', 'subcategory_code');
        $petugas = User::where('role', UserRole::PengelolaGudang)->first();
        $tanggal = CarbonImmutable::parse(self::TANGGAL_SALDO_AWAL);

        $dibuat = 0;
        $diarsipkan = 0;

        DB::transaction(function () use ($saldo, $subkategori, $petugas, $tanggal, &$dibuat, &$diarsipkan) {
            foreach (self::bacaCsv('barang.csv') as $baris) {
                $stokAwal = $saldo->get($baris['item_code'], 0);

                $item = Item::firstOrCreate(
                    ['item_code' => $baris['item_code']],
                    self::atributBarangBaru($baris, $subkategori, aktif: $stokAwal > 0),
                );

                // Hanya isi saldo awal untuk barang yang baru dibuat.
                if (! $item->wasRecentlyCreated) {
                    if ($item->subcategory_id === null) {
                        $item->update(['subcategory_id' => self::subcategoryId($baris, $subkategori)]);
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
                    notes: self::CATATAN_SALDO_AWAL,
                    date: $tanggal,
                );
            }
        });

        $this->command?->info("Master barang dibuat: {$dibuat} barang ({$diarsipkan} diarsipkan karena tanpa saldo awal).");
    }

    /**
     * Jumlah saldo awal per kode barang, dari BERKAS_SALDO_AWAL.
     *
     * @return Collection<string, int>
     */
    public static function saldoAwal(): Collection
    {
        return collect(self::bacaCsv(self::BERKAS_SALDO_AWAL))
            ->mapWithKeys(fn (array $baris) => [$baris['item_code'] => (int) $baris['qty']]);
    }

    /**
     * Atribut barang yang belum terdaftar, dari satu baris barang.csv.
     *
     * @param  array<string, string>  $baris
     * @param  Collection<string, string>  $subkategori  subcategory_code => subcategory_id
     * @return array<string, mixed>
     */
    public static function atributBarangBaru(array $baris, Collection $subkategori, bool $aktif): array
    {
        return [
            'item_name' => $baris['item_name'],
            'subcategory_id' => self::subcategoryId($baris, $subkategori),
            'unit' => SatuanBarang::from(strtolower($baris['unit'])),
            'stock_min' => 1,
            'stock_max' => 1000,
            'is_active' => $aktif,
        ];
    }

    /**
     * @param  array<string, string>  $baris
     * @param  Collection<string, string>  $subkategori
     */
    private static function subcategoryId(array $baris, Collection $subkategori): string
    {
        return $subkategori->get($baris['subcategory_code'])
            ?? throw new RuntimeException("Sub kategori {$baris['subcategory_code']} untuk barang {$baris['item_code']} tidak ditemukan.");
    }

    /**
     * @return list<array<string, string>> baris CSV berkunci nama kolom.
     */
    public static function bacaCsv(string $berkas): array
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
