<?php

use App\Enums\MutationType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockMutation;
use App\Models\User;
use App\Support\StockLedger;

function gudang(array $attributes = []): User
{
    return User::factory()->role(UserRole::PengelolaGudang)->create($attributes);
}

function mutationPayload(Item $item, array $overrides = []): array
{
    return array_merge([
        'item_id' => $item->item_id,
        'mutation_type' => MutationType::BarangRusak->value,
        'amount' => 5,
        'mutation_date' => now()->format('Y-m-d'),
        'notes' => 'Rusak terkena air saat gudang bocor.',
    ], $overrides);
}

describe('hak akses', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/mutasi')->assertRedirect('/login');
    });

    it('menolak Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get('/mutasi')
            ->assertForbidden();
    });

    it('mengizinkan Pengelola Gudang', function () {
        $this->actingAs(gudang())->get('/mutasi')->assertOk()->assertSee('Riwayat Mutasi');
    });

    it('mengizinkan Admin', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/mutasi')
            ->assertOk();
    });

    it('menolak Pejabat Pengadaan mencatat mutasi', function () {
        $item = Item::factory()->create(['stock_current' => 50]);

        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->post('/mutasi', mutationPayload($item))
            ->assertForbidden();

        expect(StockMutation::count())->toBe(0);
    });
});

describe('mencatat mutasi manual', function () {
    it('mengurangi stok untuk jenis berarah keluar', function () {
        $actor = gudang();
        $item = Item::factory()->create(['item_code' => 'ATK-001', 'stock_current' => 50]);

        $this->actingAs($actor)
            ->post('/mutasi', mutationPayload($item))
            ->assertRedirect('/mutasi')
            ->assertSessionHas('status');

        $mutation = StockMutation::first();

        expect($item->refresh()->stock_current)->toBe(45)
            ->and($mutation->amount)->toBe(-5)
            ->and($mutation->stock_after)->toBe(45)
            ->and($mutation->mutation_type)->toBe(MutationType::BarangRusak)
            ->and($mutation->user_id)->toBe($actor->user_id)
            ->and($mutation->notes)->toBe('Rusak terkena air saat gudang bocor.');

        expect(AuditLog::where('user_id', $actor->user_id)
            ->where('activity', 'like', '%Barang Rusak%ATK-001%')
            ->exists())->toBeTrue();
    });

    it('menambah stok untuk jenis berarah masuk', function () {
        $item = Item::factory()->create(['stock_current' => 50]);

        $this->actingAs(gudang())->post('/mutasi', mutationPayload($item, [
            'mutation_type' => MutationType::HibahMasuk->value,
            'amount' => 10,
            'notes' => 'Hibah dari kantor pusat.',
        ]));

        expect($item->refresh()->stock_current)->toBe(60)
            ->and(StockMutation::first()->amount)->toBe(10);
    });

    it('menghormati arah pada koreksi pencatatan', function () {
        $item = Item::factory()->create(['stock_current' => 50]);

        $this->actingAs(gudang())->post('/mutasi', mutationPayload($item, [
            'mutation_type' => MutationType::KoreksiPencatatan->value,
            'amount' => 7,
            'arah' => 'kurang',
            'notes' => 'Salah input jumlah kemarin.',
        ]));

        expect($item->refresh()->stock_current)->toBe(43)
            ->and(StockMutation::first()->amount)->toBe(-7);
    });

    it('mewajibkan arah untuk koreksi pencatatan', function () {
        $item = Item::factory()->create(['stock_current' => 50]);

        $this->actingAs(gudang())
            ->post('/mutasi', mutationPayload($item, [
                'mutation_type' => MutationType::KoreksiPencatatan->value,
                'arah' => null,
            ]))
            ->assertSessionHasErrors('arah');
    });

    // Penjaga terpenting: form manual tidak boleh membuat mutasi yang seolah
    // berasal dari pengadaan, permintaan, atau opname.
    it('menolak jenis mutasi yang hanya boleh dibuat sistem', function () {
        $item = Item::factory()->create(['stock_current' => 50]);

        foreach ([MutationType::Masuk, MutationType::Keluar, MutationType::SaldoAwal, MutationType::Penyesuaian] as $type) {
            $this->actingAs(gudang())
                ->post('/mutasi', mutationPayload($item, ['mutation_type' => $type->value]))
                ->assertSessionHasErrors('mutation_type');
        }

        expect(StockMutation::count())->toBe(0);
    });

    it('mewajibkan keterangan', function () {
        $item = Item::factory()->create(['stock_current' => 50]);

        $this->actingAs(gudang())
            ->post('/mutasi', mutationPayload($item, ['notes' => '']))
            ->assertSessionHasErrors('notes');
    });

    it('menolak jumlah nol dan tanggal masa depan', function () {
        $item = Item::factory()->create(['stock_current' => 50]);

        $this->actingAs(gudang())
            ->post('/mutasi', mutationPayload($item, ['amount' => 0]))
            ->assertSessionHasErrors('amount');

        $this->actingAs(gudang())
            ->post('/mutasi', mutationPayload($item, ['mutation_date' => now()->addDay()->format('Y-m-d')]))
            ->assertSessionHasErrors('mutation_date');
    });

    it('menolak barang yang sudah diarsipkan', function () {
        $item = Item::factory()->arsip()->create(['stock_current' => 50]);

        $this->actingAs(gudang())
            ->post('/mutasi', mutationPayload($item))
            ->assertSessionHasErrors('item_id');
    });

    // Stok tidak cukup harus jadi galat validasi ramah, bukan halaman 500.
    it('menolak mutasi keluar melebihi stok tanpa menyisakan data', function () {
        $item = Item::factory()->create(['stock_current' => 3]);

        $this->actingAs(gudang())
            ->post('/mutasi', mutationPayload($item, ['amount' => 10]))
            ->assertSessionHasErrors('amount');

        expect($item->refresh()->stock_current)->toBe(3)
            ->and(StockMutation::count())->toBe(0);
    });
});

describe('immutability', function () {
    // Baris mutasi adalah jejak audit: tidak ada rute ubah maupun hapus sama
    // sekali, sehingga permintaan ke URL-nya berujung 404.
    it('tidak menyediakan rute ubah maupun hapus', function () {
        $item = Item::factory()->create(['stock_current' => 50]);
        $mutation = StockLedger::record($item, MutationType::SaldoAwal, 10);

        $this->actingAs(gudang())
            ->put("/mutasi/{$mutation->mutation_id}", [])
            ->assertNotFound();

        $this->actingAs(gudang())
            ->delete("/mutasi/{$mutation->mutation_id}")
            ->assertNotFound();

        $this->actingAs(gudang())
            ->get("/mutasi/{$mutation->mutation_id}/edit")
            ->assertNotFound();

        expect(StockMutation::count())->toBe(1);
    });
});

describe('daftar dan penyaringan', function () {
    beforeEach(function () {
        $this->kertas = Item::factory()->create(['item_code' => 'ATK-001', 'item_name' => 'Kertas HVS A4', 'stock_current' => 0]);
        $this->tinta = Item::factory()->create(['item_code' => 'ATK-002', 'item_name' => 'Tinta Printer', 'stock_current' => 0]);

        StockLedger::record($this->kertas, MutationType::SaldoAwal, 100, date: now()->subDays(20));
        StockLedger::record($this->kertas, MutationType::BarangRusak, 10, date: now()->subDays(5));
        StockLedger::record($this->tinta, MutationType::SaldoAwal, 50, date: now()->subDays(2));
    });

    it('menyaring berdasarkan kata kunci barang', function () {
        $this->actingAs(gudang())->get('/mutasi?q=Kertas')
            ->assertSee('Kertas HVS A4')
            ->assertDontSee('Tinta Printer');
    });

    it('menyaring berdasarkan jenis mutasi', function () {
        $this->actingAs(gudang())->get('/mutasi?type='.MutationType::BarangRusak->value)
            ->assertSee('Barang Rusak')
            ->assertDontSee('Tinta Printer');
    });

    it('menyaring berdasarkan rentang tanggal', function () {
        $this->actingAs(gudang())
            ->get('/mutasi?from='.now()->subDays(7)->format('Y-m-d'))
            ->assertSee('Tinta Printer')
            ->assertSee('Barang Rusak');

        $this->actingAs(gudang())
            ->get('/mutasi?to='.now()->subDays(10)->format('Y-m-d'))
            ->assertSee('Kertas HVS A4')
            ->assertDontSee('Tinta Printer');
    });

    it('menghitung rekap masuk, keluar, dan netto', function () {
        $response = $this->actingAs(gudang())->get('/mutasi');

        // 100 + 50 masuk, 10 keluar, netto 140.
        $response->assertSee('+150')->assertSee('-10')->assertSee('+140');
    });

    it('rekap mengikuti penyaring yang aktif', function () {
        $this->actingAs(gudang())
            ->get('/mutasi?type='.MutationType::BarangRusak->value)
            ->assertSee('-10')
            ->assertDontSee('+150');
    });
});
