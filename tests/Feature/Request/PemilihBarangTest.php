<?php

use App\Enums\KategoriBarang;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemSubcategory;
use App\Models\User;
use App\Support\KatalogBarang;

/*
 * Pemilih barang pada formulir Permintaan, Permintaan Umum, dan Permohonan:
 * katalognya membawa kategori dan sub kategori untuk penyaring di peramban
 * (ADR 0043), dan ketiga formulir memakai komponen yang sama.
 */

beforeEach(function () {
    $this->alatTulis = ItemSubcategory::factory()->atk()->create([
        'subcategory_code' => '1010301001',
        'subcategory_name' => 'ALAT TULIS',
    ]);
    $this->pulpen = Item::factory()->create([
        'item_name' => 'Pulpen Hitam',
        'subcategory_id' => $this->alatTulis,
        'stock_current' => 12,
    ]);
    $this->arsip = Item::factory()->arsip()->create(['item_name' => 'Pulpen Lama']);
});

it('mengirim katalog berkategori dan bersub kategori, tanpa barang arsip', function () {
    $katalog = KatalogBarang::untukPemilih();
    $pulpen = $katalog->firstWhere('item_id', $this->pulpen->item_id);

    expect($pulpen)->toMatchArray([
        'item_name' => 'Pulpen Hitam',
        'stock_current' => 12,
        'category' => KategoriBarang::ATK->value,
        'subcategory_id' => $this->alatTulis->subcategory_id,
        'subcategory_code' => '1010301001',
        'subcategory_name' => 'ALAT TULIS',
    ])
        ->and($katalog->pluck('item_id'))->not->toContain($this->arsip->item_id);
});

it('memuat kategori dan stok tersedia di formulir permintaan', function () {
    $response = $this->actingAs(User::factory()->role(UserRole::Pengguna)->create())
        ->get(route('request.create'))
        ->assertOk()
        ->assertSee('pemilihBarang(', escape: false)
        ->assertSee('Pilih ATK atau ARTK untuk menyaring sampai sub kategorinya.')
        ->assertSee('Alat Rumah Tangga Kantor');

    $pulpen = collect($response->viewData('items'))->firstWhere('item_id', $this->pulpen->item_id);

    expect($pulpen['category'])->toBe('ATK')
        ->and($pulpen['subcategory_name'])->toBe('ALAT TULIS')
        ->and($pulpen['available'])->toBe(12);
});

it('memakai pemilih yang sama di permintaan umum dan permohonan', function (string $route) {
    $gudang = User::factory()->role(UserRole::PengelolaGudang)->create();

    $response = $this->actingAs($gudang)->get(route($route))
        ->assertOk()
        ->assertSee('pemilihBarang(', escape: false)
        ->assertSee('Pilih ATK atau ARTK untuk menyaring sampai sub kategorinya.');

    $pulpen = collect($response->viewData('items'))->firstWhere('item_id', $this->pulpen->item_id);

    expect($pulpen['subcategory_id'])->toBe($this->alatTulis->subcategory_id)
        ->and(collect($response->viewData('subcategories'))->pluck('kode')->all())->toBe(['1010301001']);
})->with(['request.umum.create', 'item-applications.create']);

// Sub kategori dikelompokkan menurut kolom category miliknya sendiri, dan
// hanya yang punya barang aktif yang ditawarkan sebagai penyaring.
it('mengirim sub kategori berkategori yang punya barang aktif', function () {
    $kabel = ItemSubcategory::factory()->artk()->create(['subcategory_code' => '1010306001', 'subcategory_name' => 'KABEL LISTRIK']);
    Item::factory()->create(['subcategory_id' => $kabel]);
    $kosong = ItemSubcategory::factory()->atk()->create(['subcategory_code' => '1010301999']);
    $hanyaArsip = ItemSubcategory::factory()->atk()->create(['subcategory_code' => '1010302001']);
    Item::factory()->arsip()->create(['subcategory_id' => $hanyaArsip]);

    $response = $this->actingAs(User::factory()->role(UserRole::Pengguna)->create())
        ->get(route('request.create'))
        ->assertOk();

    $subkategori = collect($response->viewData('subcategories'));

    expect($subkategori->pluck('kode')->all())->toBe(['1010301001', '1010306001'])
        ->and($subkategori->firstWhere('kode', '1010306001'))->toBe([
            'id' => $kabel->subcategory_id,
            'kode' => '1010306001',
            'nama' => 'KABEL LISTRIK',
            'kategori' => 'ARTK',
        ])
        ->and($subkategori->pluck('id'))->not->toContain($kosong->subcategory_id);
});

// Skrip pemilih dimuat sekali per halaman, walau komponennya dipakai lagi.
it('memuat skrip pemilih sekali saja', function () {
    $html = $this->actingAs(User::factory()->role(UserRole::Pengguna)->create())
        ->get(route('request.create'))
        ->getContent();

    expect(substr_count($html, 'function pemilihBarang('))->toBe(1);
});
