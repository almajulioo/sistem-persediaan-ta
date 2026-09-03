<?php

use App\Enums\AlasanSelisih;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\OpnamePoster;

beforeEach(function () {
    $this->petugas = User::factory()->role(UserRole::PengelolaGudang)
        ->create(['fullname' => 'Siti Pengelola']);

    $this->item = Item::factory()->create([
        'item_code' => 'ATK-001', 'item_name' => 'Kertas HVS A4', 'stock_current' => 100,
    ]);

    $this->opname = StockOpname::create([
        'opname_date' => now(),
        'user_id' => $this->petugas->user_id,
        'status' => OpnameStatus::Draf,
        'period_type' => PeriodeOpname::TriwulanIII,
        'period_year' => 2026,
    ]);

    $this->opname->details()->create([
        'item_id' => $this->item->item_id,
        'stock_stored' => 100,
        'stock_real' => 95,
        'difference' => -5,
        'counted_at' => now(),
        'adjustment_reason' => AlasanSelisih::BarangRusak,
        'adjustment_note' => 'Lima rim basah terkena rembesan atap.',
    ]);

    OpnamePoster::post($this->opname->fresh('details'), $this->petugas);
});

it('menghasilkan PDF laporan opname', function () {
    $response = $this->actingAs($this->petugas)
        ->get("/opname/{$this->opname->opname_id}/cetak");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF');
});

it('menghasilkan PDF untuk opname yang masih draf', function () {
    $draf = StockOpname::create([
        'opname_date' => now(),
        'user_id' => $this->petugas->user_id,
        'status' => OpnameStatus::Draf,
        'period_type' => PeriodeOpname::TriwulanIV,
        'period_year' => 2026,
    ]);
    $draf->details()->create([
        'item_id' => $this->item->item_id,
        'stock_stored' => 95,
    ]);

    $response = $this->actingAs($this->petugas)->get("/opname/{$draf->opname_id}/cetak");

    $response->assertOk();
    expect($response->getContent())->toStartWith('%PDF');
});

it('ditolak untuk Pejabat Pengadaan', function () {
    $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
        ->get("/opname/{$this->opname->opname_id}/cetak")
        ->assertForbidden();
});

it('menampilkan hasil opname beserta alasan selisih di layar', function () {
    $this->actingAs($this->petugas)
        ->get("/opname/{$this->opname->opname_id}")
        ->assertOk()
        ->assertSee('Kertas HVS A4')
        ->assertSee('Barang Rusak')
        ->assertSee('Lima rim basah terkena rembesan atap.')
        ->assertSee('Selesai');
});
