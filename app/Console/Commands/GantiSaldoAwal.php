<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\MutationType;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemSubcategory;
use App\Models\Procurement;
use App\Models\Request as Permintaan;
use App\Models\StockMutation;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\StockLedger;
use Carbon\CarbonImmutable;
use Database\Seeders\ItemSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mengganti saldo awal 1 Oktober 2026 dengan saldo awal 1 November 2026 pada
 * database yang sudah terisi seeder tetapi BELUM dipakai bertransaksi.
 *
 * Ini satu-satunya tempat baris mutasi dihapus, dan hanya sah karena buku
 * mutasinya belum berisi kejadian nyata — seluruh isinya hasil seeder. Begitu
 * ada transaksi, perintah ini menolak berjalan dan perubahan saldo harus lewat
 * stok opname atau Koreksi Pencatatan seperti biasa. Lihat ADR 0058.
 *
 * Tanpa --jalankan, perintah hanya menampilkan pratinjau. Menjalankannya lagi
 * setelah berhasil tidak mengubah apa pun.
 */
class GantiSaldoAwal extends Command
{
    /** Tanggal saldo awal lama yang boleh diganti. */
    private const TANGGAL_LAMA = '2026-10-01';

    protected $signature = 'persediaan:ganti-saldo-awal
                            {--jalankan : Terapkan perubahan; tanpa opsi ini hanya pratinjau}';

    protected $description = 'Mengganti saldo awal 1 Oktober 2026 dengan saldo awal 1 November 2026 (sekali pakai, sebelum sistem dipakai)';

    public function handle(): int
    {
        $tanggalBaru = ItemSeeder::TANGGAL_SALDO_AWAL;

        if ($this->sudahDiterapkan($tanggalBaru)) {
            $this->info("Saldo awal sudah per {$tanggalBaru}. Tidak ada yang diubah.");

            return self::SUCCESS;
        }

        $saldo = ItemSeeder::saldoAwal()->filter(fn (int $qty) => $qty > 0);
        $master = collect(ItemSeeder::bacaCsv('barang.csv'))->keyBy('item_code');

        $masalah = $this->periksaPengaman($saldo, $master);

        if ($masalah !== []) {
            $this->error('Dibatalkan — database ini tidak aman untuk diganti saldo awalnya:');

            foreach ($masalah as $baris) {
                $this->line("  - {$baris}");
            }

            return self::FAILURE;
        }

        $rencana = $this->susunRencana($saldo, $master);
        $this->tampilkanRencana($rencana, $saldo);

        if (! $this->option('jalankan')) {
            $this->newLine();
            $this->warn('Ini baru PRATINJAU, belum ada data yang diubah.');
            $this->line('Jalankan lagi dengan --jalankan untuk menerapkan.');

            return self::SUCCESS;
        }

        $this->terapkan($saldo, $rencana);

        // Stok berubah tanpa melewati pemeriksaan reaktif; selaraskan
        // notifikasi dan draf usulan pengadaan otomatis dengan stok baru.
        $this->call('notifikasi:periksa');

        $this->tampilkanHasil();

        return self::SUCCESS;
    }

    /**
     * Sudah diganti bila buku mutasi berisi saldo awal bertanggal baru saja.
     */
    private function sudahDiterapkan(string $tanggalBaru): bool
    {
        $mutasi = StockMutation::query();

        return $mutasi->clone()->exists()
            && ! $mutasi->clone()
                ->where(fn ($q) => $q
                    ->where('mutation_type', '!=', MutationType::SaldoAwal)
                    ->orWhereDate('mutation_date', '!=', $tanggalBaru))
                ->exists();
    }

    /**
     * @param  Collection<string, int>  $saldo
     * @param  Collection<string, array<string, string>>  $master
     * @return list<string> alasan penolakan; kosong berarti aman.
     */
    private function periksaPengaman(Collection $saldo, Collection $master): array
    {
        $masalah = [];

        $mutasiLain = StockMutation::where('mutation_type', '!=', MutationType::SaldoAwal)->count();
        if ($mutasiLain > 0) {
            $masalah[] = "Sudah ada {$mutasiLain} mutasi selain saldo awal (barang masuk/keluar/opname/manual).";
        }

        $saldoLain = StockMutation::where('mutation_type', MutationType::SaldoAwal)
            ->whereDate('mutation_date', '!=', self::TANGGAL_LAMA)
            ->count();
        if ($saldoLain > 0) {
            $masalah[] = "Ada {$saldoLain} mutasi saldo awal yang tidak bertanggal ".self::TANGGAL_LAMA
                .' (mis. barang yang didaftarkan lewat aplikasi).';
        }

        foreach ([
            'permintaan barang' => Permintaan::query(),
            'pengadaan' => Procurement::query(),
            'stok opname' => StockOpname::query(),
        ] as $label => $query) {
            if (($jumlah = $query->count()) > 0) {
                $masalah[] = "Sudah ada {$jumlah} data {$label}.";
            }
        }

        $terdaftar = Item::pluck('item_code')->flip();

        $tanpaMaster = $saldo->keys()
            ->reject(fn (string $kode) => $master->has($kode) || $terdaftar->has($kode));
        if ($tanpaMaster->isNotEmpty()) {
            $masalah[] = 'Kode di berkas saldo awal tidak ada di barang.csv: '.$tanpaMaster->implode(', ');
        }

        $subkategori = ItemSubcategory::pluck('subcategory_code')->flip();
        $tanpaSub = $master
            ->reject(fn (array $baris, string $kode) => $terdaftar->has($kode))
            ->reject(fn (array $baris) => $subkategori->has($baris['subcategory_code']))
            ->keys();
        if ($tanpaSub->isNotEmpty()) {
            $masalah[] = 'Sub kategori barang baru belum ada untuk: '.$tanpaSub->implode(', ');
        }

        return $masalah;
    }

    /**
     * @param  Collection<string, int>  $saldo
     * @param  Collection<string, array<string, string>>  $master
     * @return array{baru: Collection, aktifkan: Collection, arsipkan: Collection, berubah: int, sama: int, total_lama: int}
     */
    private function susunRencana(Collection $saldo, Collection $master): array
    {
        $items = Item::query()->get(['item_id', 'item_code', 'item_name', 'stock_current', 'is_active'])->keyBy('item_code');

        $berubah = 0;
        $sama = 0;

        foreach ($items as $kode => $item) {
            $baru = $saldo->get($kode, 0);

            // Barang yang baru bersaldo atau baru habis dihitung terpisah
            // sebagai diaktifkan/diarsipkan.
            if ($item->stock_current === 0 || $baru === 0) {
                continue;
            }

            $item->stock_current === $baru ? $sama++ : $berubah++;
        }

        return [
            'baru' => $master->reject(fn (array $baris, string $kode) => $items->has($kode))->values(),
            'aktifkan' => $items->filter(fn (Item $item, string $kode) => ! $item->is_active && $saldo->has($kode))->values(),
            'arsipkan' => $items->filter(fn (Item $item, string $kode) => $item->is_active && ! $saldo->has($kode))->values(),
            'berubah' => $berubah,
            'sama' => $sama,
            'total_lama' => (int) $items->sum('stock_current'),
        ];
    }

    /**
     * @param  Collection<string, int>  $saldo
     */
    private function tampilkanRencana(array $rencana, Collection $saldo): void
    {
        $this->info('Rencana penggantian saldo awal '.self::TANGGAL_LAMA.' → '.ItemSeeder::TANGGAL_SALDO_AWAL);

        $this->table(['Keterangan', 'Jumlah'], [
            ['Mutasi saldo awal lama yang dihapus', StockMutation::count()],
            ['Total stok sekarang (unit)', number_format($rencana['total_lama'], 0, ',', '.')],
            ['Barang bersaldo menurut berkas baru', $saldo->count()],
            ['Total stok baru (unit)', number_format($saldo->sum(), 0, ',', '.')],
            ['Barang yang jumlahnya sama', $rencana['sama']],
            ['Barang yang jumlahnya berubah', $rencana['berubah']],
            ['Barang baru didaftarkan', $rencana['baru']->count()],
            ['Barang diaktifkan kembali', $rencana['aktifkan']->count()],
            ['Barang diarsipkan (stok jadi 0)', $rencana['arsipkan']->count()],
        ]);

        $this->daftar('Barang baru:', $rencana['baru']->map(
            fn (array $b) => "{$b['item_code']}  {$b['item_name']} ({$b['unit']}) — stok {$saldo->get($b['item_code'], 0)}"
        ));
        $this->daftar('Diaktifkan kembali:', $rencana['aktifkan']->map(
            fn (Item $i) => "{$i->item_code}  {$i->item_name} — stok {$saldo->get($i->item_code)}"
        ));
        $this->daftar('Diarsipkan:', $rencana['arsipkan']->map(
            fn (Item $i) => "{$i->item_code}  {$i->item_name} — stok lama {$i->stock_current}"
        ));
    }

    private function daftar(string $judul, Collection $baris): void
    {
        if ($baris->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line($judul);
        $baris->each(fn (string $teks) => $this->line("  - {$teks}"));
    }

    /**
     * @param  Collection<string, int>  $saldo
     */
    private function terapkan(Collection $saldo, array $rencana): void
    {
        $petugas = User::where('role', UserRole::PengelolaGudang)->first();
        $tanggal = CarbonImmutable::parse(ItemSeeder::TANGGAL_SALDO_AWAL);
        $subkategori = ItemSubcategory::pluck('subcategory_id', 'subcategory_code');

        DB::transaction(function () use ($saldo, $rencana, $petugas, $tanggal, $subkategori) {
            foreach ($rencana['baru'] as $baris) {
                Item::create([
                    'item_code' => $baris['item_code'],
                    ...ItemSeeder::atributBarangBaru($baris, $subkategori, aktif: false),
                ]);
            }

            // Pengecualian sekali pakai atas ADR 0005: buku mutasi hanya berisi
            // saldo awal hasil seeder (dijaga periksaPengaman()). Lihat ADR 0058.
            StockMutation::where('mutation_type', MutationType::SaldoAwal)->delete();
            Item::query()->update(['stock_current' => 0]);

            $items = Item::query()->whereIn('item_code', $saldo->keys())->get()->keyBy('item_code');

            foreach ($saldo as $kode => $qty) {
                StockLedger::record(
                    item: $items[$kode],
                    type: MutationType::SaldoAwal,
                    amount: $qty,
                    recordedBy: $petugas,
                    notes: ItemSeeder::CATATAN_SALDO_AWAL,
                    date: $tanggal,
                );
            }

            // Sama dengan aturan seeder: barang tanpa saldo awal diarsipkan.
            Item::query()->whereIn('item_code', $saldo->keys())->update(['is_active' => true]);
            Item::query()->whereNotIn('item_code', $saldo->keys())->update(['is_active' => false]);
        });

        $ringkas = "Saldo awal diganti dari per 1 Oktober 2026 menjadi per 1 November 2026: {$saldo->count()} barang, "
            .number_format($saldo->sum(), 0, ',', '.').' unit; '.$rencana['baru']->count().' barang baru (ADR 0058).';

        Log::info($ringkas);

        $pelaku = User::where('role', UserRole::Admin)->first() ?? $petugas;
        if ($pelaku !== null) {
            AuditLogger::record(user: $pelaku, activity: $ringkas, module: AuditModule::Mutasi, event: AuditEvent::Ubah);
        }
    }

    private function tampilkanHasil(): void
    {
        $this->newLine();
        $this->info('Selesai. Periksa angka berikut:');
        $this->table(['Pemeriksaan', 'Hasil'], [
            ['Mutasi saldo awal', StockMutation::where('mutation_type', MutationType::SaldoAwal)->count()],
            ['Tanggal saldo awal', StockMutation::query()->distinct()->pluck('mutation_date')->map->format('Y-m-d')->implode(', ')],
            ['Total stok (unit)', number_format((int) Item::sum('stock_current'), 0, ',', '.')],
            ['Barang aktif', Item::aktif()->count()],
            ['Seluruh barang', Item::count()],
        ]);
    }
}
