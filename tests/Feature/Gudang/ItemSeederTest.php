<?php

use App\Enums\MutationType;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemSubcategory;
use App\Models\StockMutation;
use App\Models\User;
use Database\Seeders\ItemSeeder;
use Database\Seeders\ItemSubcategorySeeder;

// Memastikan data kantor di database/seeders/data/ terbaca utuh: setiap
// barang mendapat sub kategori, dan kategorinya cocok (ADR 0043).

it('memuat sub kategori, master barang, dan saldo awal dari berkas CSV', function () {
    User::factory()->role(UserRole::PengelolaGudang)->create();

    $this->seed([ItemSubcategorySeeder::class, ItemSeeder::class]);

    $tanpaSubKategori = Item::whereNull('subcategory_id')->count();
    $kategoriBerbeda = Item::query()
        ->join('item_subcategories', 'item_subcategories.subcategory_id', '=', 'items.subcategory_id')
        ->whereColumn('items.category', '!=', 'item_subcategories.category')
        ->count();

    expect(ItemSubcategory::count())->toBe(75)
        ->and(Item::count())->toBe(1801)
        ->and($tanpaSubKategori)->toBe(0)
        ->and($kategoriBerbeda)->toBe(0)
        ->and(Item::where('is_active', true)->count())->toBe(120)
        ->and((int) StockMutation::where('mutation_type', MutationType::SaldoAwal)->sum('amount'))->toBe(1792);

    // Idempoten: menjalankan ulang tidak menggandakan apa pun.
    $this->seed([ItemSubcategorySeeder::class, ItemSeeder::class]);

    expect(ItemSubcategory::count())->toBe(75)
        ->and(Item::count())->toBe(1801)
        ->and(StockMutation::count())->toBe(120);
});

it('mengisi sub kategori barang lama yang belum punya', function () {
    User::factory()->role(UserRole::PengelolaGudang)->create();
    $this->seed(ItemSubcategorySeeder::class);

    $lama = Item::factory()->create([
        'item_code' => '1010301001000017',
        'subcategory_id' => null,
        'stock_current' => 7,
    ]);

    $this->seed(ItemSeeder::class);

    $lama->refresh();

    expect($lama->subcategory?->subcategory_code)->toBe('1010301001')
        ->and($lama->category?->value)->toBe('ATK')
        ->and($lama->stock_current)->toBe(7);
});
