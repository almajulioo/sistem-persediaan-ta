<?php

use App\Enums\MutationType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Item;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\StockLedger;

it('menambah stok untuk mutasi masuk', function () {
    $item = Item::factory()->create(['stock_current' => 10]);

    $mutation = StockLedger::record($item, MutationType::Masuk, 15);

    expect($item->refresh()->stock_current)->toBe(25)
        ->and($mutation->amount)->toBe(15)
        ->and($mutation->mutation_type)->toBe(MutationType::Masuk);
});

it('mengurangi stok untuk mutasi keluar dan menyimpan jumlah bertanda negatif', function () {
    $item = Item::factory()->create(['stock_current' => 10]);

    $mutation = StockLedger::record($item, MutationType::Keluar, 4);

    expect($item->refresh()->stock_current)->toBe(6)
        ->and($mutation->amount)->toBe(-4);
});

it('menolak mutasi keluar yang melebihi stok tersedia', function () {
    $item = Item::factory()->create(['stock_current' => 3, 'item_name' => 'Kertas HVS A4']);

    expect(fn () => StockLedger::record($item, MutationType::Keluar, 5))
        ->toThrow(InsufficientStockException::class);

    // Stok dan buku mutasi tidak berubah sedikit pun.
    expect($item->refresh()->stock_current)->toBe(3)
        ->and($item->stockMutations()->count())->toBe(0);
});

it('menyertakan nama barang dan satuan pada pesan galat', function () {
    $item = Item::factory()->create(['stock_current' => 3, 'item_name' => 'Kertas HVS A4']);

    try {
        StockLedger::record($item, MutationType::Keluar, 5);
    } catch (InsufficientStockException $e) {
        expect($e->getMessage())->toContain('Kertas HVS A4')
            ->and($e->getMessage())->toContain('tersedia 3');

        return;
    }

    $this->fail('InsufficientStockException tidak dilempar.');
});

it('mengizinkan penyesuaian naik maupun turun', function () {
    $item = Item::factory()->create(['stock_current' => 10]);

    StockLedger::record($item, MutationType::Penyesuaian, -3);
    expect($item->refresh()->stock_current)->toBe(7);

    StockLedger::record($item, MutationType::Penyesuaian, 5);
    expect($item->refresh()->stock_current)->toBe(12);
});

it('menolak jumlah nol atau negatif untuk mutasi berarah tetap', function () {
    $item = Item::factory()->create(['stock_current' => 10]);

    expect(fn () => StockLedger::record($item, MutationType::Masuk, 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => StockLedger::record($item, MutationType::Keluar, -5))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => StockLedger::record($item, MutationType::Penyesuaian, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('mencatat pencatat mutasi bila diberikan', function () {
    $item = Item::factory()->create(['stock_current' => 0]);
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create(['fullname' => 'Siti Gudang']);

    $mutation = StockLedger::record(
        item: $item,
        type: MutationType::SaldoAwal,
        amount: 10,
        recordedBy: $petugas,
        notes: 'Stok pembuka',
    );

    expect($mutation->user_id)->toBe($petugas->user_id)
        ->and($mutation->petugas())->toBe('Siti Gudang')
        ->and($mutation->notes)->toBe('Stok pembuka');
});

it('menampilkan Sistem sebagai petugas bila mutasi tanpa pencatat', function () {
    $item = Item::factory()->create(['stock_current' => 0]);

    $mutation = StockLedger::record($item, MutationType::SaldoAwal, 10);

    expect($mutation->user_id)->toBeNull()
        ->and($mutation->petugas())->toBe('Sistem');
});

it('mengisi stock_after dengan saldo setelah tiap mutasi', function () {
    $item = Item::factory()->create(['stock_current' => 0]);

    $a = StockLedger::record($item, MutationType::SaldoAwal, 100);
    $b = StockLedger::record($item, MutationType::Keluar, 30);
    $c = StockLedger::record($item, MutationType::HibahMasuk, 12);

    expect($a->stock_after)->toBe(100)
        ->and($b->stock_after)->toBe(70)
        ->and($c->stock_after)->toBe(82)
        ->and($item->refresh()->stock_current)->toBe(82);
});

it('menentukan arah dari jenis mutasi manual', function () {
    $item = Item::factory()->create(['stock_current' => 50]);

    // Jenis manual berarah keluar tetap dikirim sebagai angka positif.
    expect(StockLedger::record($item, MutationType::BarangRusak, 5)->amount)->toBe(-5)
        ->and(StockLedger::record($item, MutationType::BarangHilang, 3)->amount)->toBe(-3)
        ->and(StockLedger::record($item, MutationType::HibahMasuk, 8)->amount)->toBe(8)
        ->and(StockLedger::record($item, MutationType::ReturDariUnit, 2)->amount)->toBe(2);

    expect($item->refresh()->stock_current)->toBe(52);
});

it('menjaga stock_current selalu sama dengan jumlah seluruh mutasi', function () {
    $item = Item::factory()->create(['stock_current' => 0]);

    StockLedger::record($item, MutationType::SaldoAwal, 100);
    StockLedger::record($item, MutationType::Keluar, 30);
    StockLedger::record($item, MutationType::Masuk, 12);
    StockLedger::record($item, MutationType::Penyesuaian, -2);

    expect($item->refresh()->stock_current)
        ->toBe(80)
        ->toBe((int) $item->stockMutations()->sum('amount'));
});

it('menurunkan jenis dokumen dari morph map, bukan dari argumen', function () {
    $item = Item::factory()->create(['stock_current' => 0]);
    $opname = StockOpname::create([
        'opname_date' => now(),
        'user_id' => User::factory()->role(UserRole::PengelolaGudang)->create()->user_id,
    ]);

    $mutation = StockLedger::record(
        item: $item,
        type: MutationType::Masuk,
        amount: 5,
        reference: $opname,
        referenceNumber: '001/PPBJ/2026',
    );

    expect($mutation->reference_id)->toBe($opname->getKey())
        // Alias berasal dari morph map di AppServiceProvider, jadi tidak mungkin
        // tidak cocok dengan model yang ditunjuk.
        ->and($mutation->reference_type)->toBe('opname')
        ->and($mutation->reference_number)->toBe('001/PPBJ/2026');
});

it('mengembalikan model dokumen lewat relasi polimorfik', function () {
    $item = Item::factory()->create(['stock_current' => 0]);
    $opname = StockOpname::create([
        'opname_date' => now(),
        'user_id' => User::factory()->role(UserRole::PengelolaGudang)->create()->user_id,
    ]);

    $mutation = StockLedger::record($item, MutationType::SaldoAwal, 5, reference: $opname);

    expect($mutation->fresh()->reference)->toBeInstanceOf(StockOpname::class)
        ->and($mutation->fresh()->reference->opname_id)->toBe($opname->opname_id);
});

it('membiarkan referensi kosong untuk mutasi tanpa dokumen', function () {
    $item = Item::factory()->create(['stock_current' => 50]);

    $mutation = StockLedger::record($item, MutationType::BarangRusak, 5);

    expect($mutation->reference_id)->toBeNull()
        ->and($mutation->reference_type)->toBeNull()
        ->and($mutation->reference_number)->toBeNull()
        ->and($mutation->fresh()->reference)->toBeNull()
        ->and($mutation->sumber())->toBe('Manual');
});

it('memperbarui instance yang dipegang pemanggil tanpa perlu refresh', function () {
    $item = Item::factory()->create(['stock_current' => 10]);

    StockLedger::record($item, MutationType::Masuk, 5);

    expect($item->stock_current)->toBe(15)
        ->and($item->isDirty())->toBeFalse();
});
