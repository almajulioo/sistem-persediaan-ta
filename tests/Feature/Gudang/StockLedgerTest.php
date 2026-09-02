<?php

use App\Enums\MutationType;
use App\Exceptions\InsufficientStockException;
use App\Models\Item;
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

it('menyimpan referensi transaksi asal', function () {
    $item = Item::factory()->create(['stock_current' => 0]);

    $mutation = StockLedger::record($item, MutationType::Masuk, 5, 'ref-123', 'pengadaan');

    expect($mutation->reference)->toBe('ref-123')
        ->and($mutation->reference_type)->toBe('pengadaan');
});

it('memperbarui instance yang dipegang pemanggil tanpa perlu refresh', function () {
    $item = Item::factory()->create(['stock_current' => 10]);

    StockLedger::record($item, MutationType::Masuk, 5);

    expect($item->stock_current)->toBe(15)
        ->and($item->isDirty())->toBeFalse();
});
