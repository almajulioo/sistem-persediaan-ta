<?php

use App\Enums\AuditModule;
use App\Enums\KategoriBarang;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemSubcategory;
use App\Models\User;

// Helper sengaja tidak dipakai di sini: tiap peran dibuat langsung supaya
// berkas ini dapat dijalankan sendiri.

describe('hak akses sub kategori', function () {
    it('mengizinkan Pengelola Gudang dan Admin mengelola', function (UserRole $role) {
        $this->actingAs(User::factory()->role($role)->create())
            ->get('/sub-kategori-barang/create')
            ->assertOk()
            ->assertSee('Tambah Sub Kategori');
    })->with([UserRole::PengelolaGudang, UserRole::Admin]);

    // Kasubbag Umum dan TI pengawas baca-saja gudang (ADR 0041).
    it('membuka daftar baca-saja bagi Kasubbag Umum dan TI', function () {
        $kasubbag = User::factory()->role(UserRole::KasubbagUmumTi)->create();
        $subcategory = ItemSubcategory::factory()->create();

        $this->actingAs($kasubbag)->get('/sub-kategori-barang')
            ->assertOk()
            ->assertSee($subcategory->subcategory_name)
            ->assertDontSee('Tambah Sub Kategori');

        $this->actingAs($kasubbag)->get('/sub-kategori-barang/create')->assertForbidden();
        $this->actingAs($kasubbag)
            ->post('/sub-kategori-barang', ['subcategory_code' => '1010301999', 'subcategory_name' => 'X', 'category' => 'ATK'])
            ->assertForbidden();
    });

    it('menolak Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get('/sub-kategori-barang')
            ->assertForbidden();
    });

    it('menampilkan menu bagi Pengelola Gudang', function () {
        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->get('/dasbor')
            ->assertSee('Sub Kategori Barang');
    });
});

describe('menambah sub kategori', function () {
    it('menyimpan sub kategori dan mencatat log aktivitas', function () {
        $gudang = User::factory()->role(UserRole::PengelolaGudang)->create();

        $this->actingAs($gudang)
            ->post('/sub-kategori-barang', [
                'subcategory_code' => ' 1010301099 ',
                'subcategory_name' => 'SPIDOL WARNA',
                'category' => KategoriBarang::ATK->value,
            ])
            ->assertRedirect('/sub-kategori-barang')
            ->assertSessionHas('status');

        $subcategory = ItemSubcategory::where('subcategory_code', '1010301099')->first();

        expect($subcategory)->not->toBeNull()
            ->and($subcategory->category)->toBe(KategoriBarang::ATK);

        $log = AuditLog::where('module', AuditModule::SubKategoriBarang)->first();

        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($gudang->user_id)
            ->and($log->activity)->toContain('SPIDOL WARNA');
    });

    it('menolak kode yang sudah dipakai dan kategori di luar daftar', function () {
        ItemSubcategory::factory()->create(['subcategory_code' => '1010301001']);

        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->post('/sub-kategori-barang', [
                'subcategory_code' => '1010301001',
                'subcategory_name' => 'ALAT TULIS',
                'category' => 'ATRK',
            ])
            ->assertSessionHasErrors(['subcategory_code', 'category']);

        expect(ItemSubcategory::count())->toBe(1);
    });

    it('langsung dapat dipilih di formulir barang', function () {
        $gudang = User::factory()->role(UserRole::PengelolaGudang)->create();

        $this->actingAs($gudang)->post('/sub-kategori-barang', [
            'subcategory_code' => '1010306099',
            'subcategory_name' => 'LAMPU HIAS',
            'category' => KategoriBarang::ARTK->value,
        ]);

        $this->actingAs($gudang)->get('/barang/create')->assertSee('1010306099 — LAMPU HIAS');
    });
});

describe('mengubah sub kategori', function () {
    // items.category adalah salinan kategori sub kategorinya (ADR 0043).
    it('memindahkan kategori seluruh barangnya', function () {
        $subcategory = ItemSubcategory::factory()->atk()->create();
        $items = Item::factory()->count(2)->create(['subcategory_id' => $subcategory]);
        $lain = Item::factory()->create(['subcategory_id' => ItemSubcategory::factory()->atk()]);

        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->put("/sub-kategori-barang/{$subcategory->subcategory_id}", [
                'subcategory_code' => $subcategory->subcategory_code,
                'subcategory_name' => 'NAMA BARU',
                'category' => KategoriBarang::ARTK->value,
            ])
            ->assertRedirect('/sub-kategori-barang');

        expect($subcategory->refresh()->subcategory_name)->toBe('NAMA BARU')
            ->and($subcategory->category)->toBe(KategoriBarang::ARTK)
            ->and($items->map(fn (Item $item) => $item->refresh()->category)->unique()->all())->toBe([KategoriBarang::ARTK])
            ->and($lain->refresh()->category)->toBe(KategoriBarang::ATK);
    });

    it('boleh menyimpan ulang kodenya sendiri', function () {
        $subcategory = ItemSubcategory::factory()->create(['subcategory_code' => '1010301001']);

        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->put("/sub-kategori-barang/{$subcategory->subcategory_id}", [
                'subcategory_code' => '1010301001',
                'subcategory_name' => $subcategory->subcategory_name,
                'category' => $subcategory->category->value,
            ])
            ->assertSessionHasNoErrors();
    });
});

describe('menghapus sub kategori', function () {
    it('menghapus sub kategori yang belum dipakai', function () {
        $subcategory = ItemSubcategory::factory()->create();

        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->delete("/sub-kategori-barang/{$subcategory->subcategory_id}")
            ->assertRedirect('/sub-kategori-barang')
            ->assertSessionHas('status');

        expect(ItemSubcategory::find($subcategory->subcategory_id))->toBeNull();
    });

    it('menolak menghapus sub kategori yang masih dipakai barang', function () {
        $subcategory = ItemSubcategory::factory()->create();
        Item::factory()->create(['subcategory_id' => $subcategory]);

        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->delete("/sub-kategori-barang/{$subcategory->subcategory_id}")
            ->assertSessionHas('error');

        expect(ItemSubcategory::find($subcategory->subcategory_id))->not->toBeNull();
    });
});

describe('daftar sub kategori', function () {
    it('menyaring menurut kata kunci dan kategori, dan menautkan jumlah barang', function () {
        $alatTulis = ItemSubcategory::factory()->atk()->create(['subcategory_name' => 'ALAT TULIS']);
        ItemSubcategory::factory()->artk()->create(['subcategory_name' => 'KABEL LISTRIK']);
        Item::factory()->count(3)->create(['subcategory_id' => $alatTulis]);
        $gudang = User::factory()->role(UserRole::PengelolaGudang)->create();

        $this->actingAs($gudang)->get('/sub-kategori-barang?q=kabel')
            ->assertSee('KABEL LISTRIK')->assertDontSee('ALAT TULIS');

        $this->actingAs($gudang)->get('/sub-kategori-barang?category='.KategoriBarang::ATK->value)
            ->assertSee('ALAT TULIS')->assertDontSee('KABEL LISTRIK')
            ->assertSee('3 barang')
            ->assertSee(route('items.index', ['subcategory' => $alatTulis->subcategory_id]), escape: false);
    });
});
