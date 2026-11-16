<?php

use App\Enums\MutationType;
use App\Enums\SatuanBarang;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemSubcategory;
use App\Models\StockMutation;
use App\Models\User;
use App\Support\StockLedger;
use Carbon\CarbonImmutable;
use Database\Seeders\ItemSubcategorySeeder;

// Perintah sekali pakai yang mengganti saldo awal 1 Oktober dengan saldo awal
// 1 November di server yang belum dipakai bertransaksi (ADR 0058).

/**
 * Keadaan server sebelum diganti: beberapa barang bersaldo awal 1 Oktober.
 *
 * @return array<string, Item>
 */
function keadaanSaldoOktober(): array
{
    test()->seed(ItemSubcategorySeeder::class);
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    User::factory()->role(UserRole::Admin)->create();

    $barang = [];

    foreach ([
        // kode => [stok 1 Okt, aktif]
        '1010301001000017' => [4, true],   // sama di November (4)
        '1010301003000004' => [9, true],   // naik menjadi 69
        '1010309001000001' => [24, true],  // Materai, habis di November
        '1010306003000004' => [0, false],  // terarsip, bersaldo 9 di November
    ] as $kode => [$stok, $aktif]) {
        $item = Item::factory()->create([
            'item_code' => $kode,
            'subcategory_id' => ItemSubcategory::where('subcategory_code', substr($kode, 0, 10))->value('subcategory_id'),
            'stock_current' => 0,
            'is_active' => $aktif,
        ]);

        if ($stok > 0) {
            StockLedger::record(
                item: $item,
                type: MutationType::SaldoAwal,
                amount: $stok,
                recordedBy: $petugas,
                notes: 'Saldo awal per 1 Oktober 2026.',
                date: CarbonImmutable::parse('2026-10-01'),
            );
        }

        $barang[$kode] = $item;
    }

    return $barang;
}

it('hanya menampilkan pratinjau tanpa --jalankan', function () {
    $barang = keadaanSaldoOktober();

    $this->artisan('persediaan:ganti-saldo-awal')
        ->expectsOutputToContain('PRATINJAU')
        ->assertSuccessful();

    expect(StockMutation::count())->toBe(3)
        ->and($barang['1010301003000004']->refresh()->stock_current)->toBe(9)
        ->and(Item::where('item_code', '1010306004000007')->exists())->toBeFalse();
});

it('mengganti saldo awal 1 Oktober dengan saldo awal 1 November', function () {
    $barang = keadaanSaldoOktober();

    $this->artisan('persediaan:ganti-saldo-awal --jalankan')->assertSuccessful();

    $mutasi = StockMutation::query()->get();

    expect($mutasi)->toHaveCount(122)
        ->and($mutasi->every(fn ($m) => $m->mutation_type === MutationType::SaldoAwal))->toBeTrue()
        ->and($mutasi->every(fn ($m) => $m->mutation_date->toDateString() === '2026-11-01'))->toBeTrue()
        ->and((int) $mutasi->sum('amount'))->toBe(1902)
        ->and((int) Item::sum('stock_current'))->toBe(1902)
        ->and(Item::aktif()->count())->toBe(122);

    expect($barang['1010301001000017']->refresh())
        ->stock_current->toBe(4)->is_active->toBeTrue();
    expect($barang['1010301003000004']->refresh()->stock_current)->toBe(69);
    expect($barang['1010309001000001']->refresh())
        ->stock_current->toBe(0)->is_active->toBeFalse();
    expect($barang['1010306003000004']->refresh())
        ->stock_current->toBe(9)->is_active->toBeTrue();

    $mcb = Item::where('item_code', '1010306004000007')->firstOrFail();
    expect($mcb->item_name)->toBe('MCB 16 Schneider')
        ->and($mcb->unit)->toBe(SatuanBarang::Pcs)
        ->and($mcb->stock_current)->toBe(4)
        ->and($mcb->is_active)->toBeTrue()
        ->and($mcb->subcategory->subcategory_code)->toBe('1010306004');

    // Stok setiap barang cocok dengan saldo berjalan mutasinya.
    $tidakCocok = Item::query()->where('stock_current', '>', 0)->get()
        ->filter(fn (Item $item) => $item->stockMutations()->sum('amount') != $item->stock_current);
    expect($tidakCocok)->toBeEmpty();

    // Menjalankan ulang tidak mengubah apa pun.
    $this->artisan('persediaan:ganti-saldo-awal --jalankan')
        ->expectsOutputToContain('sudah per 2026-11-01')
        ->assertSuccessful();

    expect(StockMutation::count())->toBe(122)
        ->and((int) Item::sum('stock_current'))->toBe(1902);
});

it('menolak berjalan bila sudah ada transaksi', function () {
    $barang = keadaanSaldoOktober();

    StockLedger::record(
        item: $barang['1010301003000004'],
        type: MutationType::BarangRusak,
        amount: 1,
        notes: 'Kemasan rusak.',
    );

    $this->artisan('persediaan:ganti-saldo-awal --jalankan')
        ->expectsOutputToContain('Dibatalkan')
        ->assertFailed();

    expect(StockMutation::count())->toBe(4)
        ->and($barang['1010301003000004']->refresh()->stock_current)->toBe(8);
});

it('menolak berjalan bila ada barang yang didaftarkan lewat aplikasi', function () {
    $barang = keadaanSaldoOktober();

    StockLedger::record(
        item: $barang['1010306003000004'],
        type: MutationType::SaldoAwal,
        amount: 2,
        notes: 'Stok pembuka saat barang didaftarkan.',
    );

    $this->artisan('persediaan:ganti-saldo-awal --jalankan')->assertFailed();

    expect(StockMutation::count())->toBe(4);
});
