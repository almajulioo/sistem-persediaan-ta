<?php

use App\Enums\AlasanSelisih;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockOpname;
use App\Models\User;

function petugasGudang(array $attributes = []): User
{
    return User::factory()->role(UserRole::PengelolaGudang)->create($attributes);
}

function opnamePayload(array $overrides = []): array
{
    return array_merge([
        'period_type' => PeriodeOpname::TriwulanIII->value,
        'period_year' => now()->year,
        'opname_date' => now()->format('Y-m-d'),
        'notes' => 'Opname rutin triwulanan.',
    ], $overrides);
}

/**
 * Buat draf opname beserta rinciannya lewat alur aplikasi.
 *
 * Id diambil dari tujuan redirect, bukan dari `latest('created_at')`: dua draf
 * yang dibuat pada detik yang sama membuat urutannya tidak pasti.
 */
function buatDraf(User $actor, array $overrides = []): StockOpname
{
    $response = test()->actingAs($actor)->post('/opname', opnamePayload($overrides));

    $id = (string) str($response->headers->get('Location'))->match('#/opname/([^/]+)/edit#');

    return StockOpname::findOrFail($id);
}

describe('hak akses', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/opname')->assertRedirect('/login');
    });

    it('menolak Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get('/opname')
            ->assertForbidden();
    });

    it('mengizinkan Pengelola Gudang', function () {
        $this->actingAs(petugasGudang())->get('/opname')->assertOk()->assertSee('Stok Opname');
    });

    // Jebakan middleware: `role:` akan menolak Admin, gate `can:` tidak.
    it('mengizinkan Admin', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/opname')
            ->assertOk();
    });

    it('menolak Pejabat Pengadaan membuat opname', function () {
        Item::factory()->create();

        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->post('/opname', opnamePayload())
            ->assertForbidden();

        expect(StockOpname::count())->toBe(0);
    });
});

describe('membuat draf', function () {
    it('menyalin seluruh barang aktif beserta stok tercatatnya', function () {
        $actor = petugasGudang();
        Item::factory()->create(['item_code' => 'ATK-001', 'stock_current' => 120]);
        Item::factory()->create(['item_code' => 'ATK-002', 'stock_current' => 45]);

        $this->actingAs($actor)
            ->post('/opname', opnamePayload())
            ->assertSessionHas('status');

        $opname = StockOpname::firstOrFail();

        expect($opname->status)->toBe(OpnameStatus::Draf)
            ->and($opname->period_type)->toBe(PeriodeOpname::TriwulanIII)
            ->and($opname->details)->toHaveCount(2)
            ->and($opname->details->firstWhere('stock_stored', 120))->not->toBeNull()
            ->and($opname->details->firstWhere('stock_stored', 45))->not->toBeNull()
            // Belum dicacah, jadi hasil cacah dan selisih masih kosong.
            ->and($opname->details->pluck('stock_real')->filter())->toBeEmpty();

        expect(AuditLog::where('user_id', $actor->user_id)
            ->where('activity', 'like', 'Membuat draf%')->exists())->toBeTrue();
    });

    it('tidak menyertakan barang yang diarsipkan', function () {
        Item::factory()->create(['item_code' => 'ATK-001']);
        Item::factory()->arsip()->create(['item_code' => 'ATK-999']);

        $opname = buatDraf(petugasGudang());

        expect($opname->details)->toHaveCount(1)
            ->and($opname->details->first()->item->item_code)->toBe('ATK-001');
    });

    it('menolak bila belum ada barang aktif', function () {
        $this->actingAs(petugasGudang())
            ->post('/opname', opnamePayload())
            ->assertSessionHas('error');

        expect(StockOpname::count())->toBe(0);
    });

    it('menolak tanggal masa depan dan periode tak dikenal', function () {
        Item::factory()->create();

        $this->actingAs(petugasGudang())
            ->post('/opname', opnamePayload(['opname_date' => now()->addDay()->format('Y-m-d')]))
            ->assertSessionHasErrors('opname_date');

        $this->actingAs(petugasGudang())
            ->post('/opname', opnamePayload(['period_type' => 'caturwulan']))
            ->assertSessionHasErrors('period_type');
    });
});

describe('menyimpan hasil cacah', function () {
    it('menyimpan sebagian dan tetap berstatus draf', function () {
        $dicacah = Item::factory()->create(['stock_current' => 100]);
        $belum = Item::factory()->create(['stock_current' => 50]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);

        // Dipilih lewat item_id, bukan urutan koleksi: kode barang dari factory
        // acak sehingga urutan rincian tidak dapat diandalkan.
        $details = $opname->details->keyBy('item_id');

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$details[$dicacah->item_id]->opname_detail_id => ['stock_real' => 100]],
        ])->assertSessionHasNoErrors();

        expect($details[$dicacah->item_id]->refresh()->stock_real)->toBe(100)
            ->and($details[$dicacah->item_id]->difference)->toBe(0)
            ->and($details[$dicacah->item_id]->counted_at)->not->toBeNull()
            // Barang kedua belum dicacah, jadi opname tetap draf.
            ->and($details[$belum->item_id]->refresh()->stock_real)->toBeNull()
            ->and($opname->refresh()->status)->toBe(OpnameStatus::Draf);
    });

    it('menghitung selisih terhadap stok tercatat', function () {
        Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$detail->opname_detail_id => [
                'stock_real' => 95,
                'adjustment_reason' => AlasanSelisih::BarangRusak->value,
                'adjustment_note' => 'Lima rim rusak terkena air.',
            ]],
        ])->assertSessionHasNoErrors();

        expect($detail->refresh()->difference)->toBe(-5)
            ->and($detail->adjustment_reason)->toBe(AlasanSelisih::BarangRusak);
    });

    // Kewajiban ini dinilai terhadap stock_stored di basis data, bukan nilai
    // yang dikirim, supaya tidak dapat dipalsukan.
    it('mewajibkan alasan dan keterangan bila berselisih', function () {
        Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$detail->opname_detail_id => ['stock_real' => 90]],
        ])->assertSessionHasErrors([
            "details.{$detail->opname_detail_id}.adjustment_reason",
            "details.{$detail->opname_detail_id}.adjustment_note",
        ]);

        expect($detail->refresh()->stock_real)->toBeNull();
    });

    it('tidak mewajibkan alasan bila cocok', function () {
        Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$detail->opname_detail_id => ['stock_real' => 100]],
        ])->assertSessionHasNoErrors();
    });
});

describe('opname selesai bersifat final', function () {
    it('menolak diubah, dihapus, dan diposting ulang', function () {
        Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$detail->opname_detail_id => ['stock_real' => 100]],
        ]);
        $this->actingAs($actor)->post("/opname/{$opname->opname_id}/posting");

        expect($opname->refresh()->status)->toBe(OpnameStatus::Selesai);

        $this->actingAs($actor)->get("/opname/{$opname->opname_id}/edit")
            ->assertRedirect(route('opname.show', $opname));

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$detail->opname_detail_id => ['stock_real' => 5]],
        ])->assertSessionHas('error');

        $this->actingAs($actor)->post("/opname/{$opname->opname_id}/posting")
            ->assertSessionHas('error');

        $this->actingAs($actor)->delete("/opname/{$opname->opname_id}")
            ->assertSessionHas('error');

        expect(StockOpname::find($opname->opname_id))->not->toBeNull()
            ->and($detail->refresh()->stock_real)->toBe(100);
    });

    it('mengizinkan draf dihapus', function () {
        Item::factory()->create();

        $actor = petugasGudang();
        $opname = buatDraf($actor);

        $this->actingAs($actor)
            ->delete("/opname/{$opname->opname_id}")
            ->assertRedirect('/opname')
            ->assertSessionHas('status');

        expect(StockOpname::find($opname->opname_id))->toBeNull();
    });
});

describe('daftar dan penyaringan', function () {
    // Diperiksa lewat URL detail tiap opname, bukan label periode: label yang
    // sama juga muncul pada dropdown penyaring sehingga tidak membedakan baris.
    it('menyaring berdasarkan periode, tahun, dan status', function () {
        Item::factory()->create();
        $actor = petugasGudang();

        $lama = buatDraf($actor, ['period_type' => PeriodeOpname::TriwulanI->value, 'period_year' => 2025]);
        $baru = buatDraf($actor, ['period_type' => PeriodeOpname::TriwulanIII->value, 'period_year' => 2026]);

        $urlLama = route('opname.show', $lama);
        $urlBaru = route('opname.show', $baru);

        $this->actingAs($actor)->get('/opname?period='.PeriodeOpname::TriwulanI->value)
            ->assertSee($urlLama)->assertDontSee($urlBaru);

        $this->actingAs($actor)->get('/opname?year=2026')
            ->assertSee($urlBaru)->assertDontSee($urlLama);

        $this->actingAs($actor)->get('/opname?status='.OpnameStatus::Selesai->value)
            ->assertSee('Belum ada stok opname')
            ->assertDontSee($urlLama)
            ->assertDontSee($urlBaru);
    });
});
