<?php

use App\Enums\AlasanSelisih;
use App\Enums\MutationType;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\UserRole;
use App\Exceptions\OpnameTidakSiapException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockMutation;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\OpnamePoster;
use App\Support\StockLedger;

/**
 * Rakit draf opname langsung lewat model agar uji posting tidak bergantung
 * pada lapisan HTTP.
 */
function drafDenganHasilCacah(User $petugas, array $hasil): StockOpname
{
    $opname = StockOpname::create([
        'opname_date' => now(),
        'user_id' => $petugas->user_id,
        'status' => OpnameStatus::Draf,
        'period_type' => PeriodeOpname::TriwulanIII,
        'period_year' => now()->year,
    ]);

    foreach ($hasil as [$item, $stokFisik, $alasan, $keterangan]) {
        $opname->details()->create([
            'item_id' => $item->item_id,
            'stock_stored' => $item->stock_current,
            'stock_real' => $stokFisik,
            'difference' => $stokFisik === null ? null : $stokFisik - $item->stock_current,
            'counted_at' => $stokFisik === null ? null : now(),
            'adjustment_reason' => $alasan,
            'adjustment_note' => $keterangan,
        ]);
    }

    return $opname->fresh('details');
}

it('menolak posting bila masih ada barang belum dicacah', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $item = Item::factory()->create(['stock_current' => 100]);

    $opname = drafDenganHasilCacah($petugas, [[$item, null, null, null]]);

    expect(fn () => OpnamePoster::post($opname, $petugas))
        ->toThrow(OpnameTidakSiapException::class);

    expect($opname->refresh()->status)->toBe(OpnameStatus::Draf)
        ->and(StockMutation::count())->toBe(0);
});

it('menolak posting bila ada selisih tanpa alasan', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $item = Item::factory()->create(['item_code' => 'ATK-001', 'stock_current' => 100]);

    $opname = drafDenganHasilCacah($petugas, [[$item, 90, null, null]]);

    expect(fn () => OpnamePoster::post($opname, $petugas))
        ->toThrow(OpnameTidakSiapException::class, 'Barang ATK-001');

    expect($item->refresh()->stock_current)->toBe(100);
});

it('menerapkan selisih hasil cacah ke stok', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $kurang = Item::factory()->create(['stock_current' => 100]);
    $lebih = Item::factory()->create(['stock_current' => 40]);

    $opname = drafDenganHasilCacah($petugas, [
        [$kurang, 95, AlasanSelisih::BarangRusak, 'Lima rim rusak.'],
        [$lebih, 43, AlasanSelisih::TransaksiBelumDibukukan, 'Penerimaan belum dicatat.'],
    ]);

    OpnamePoster::post($opname, $petugas);

    expect($kurang->refresh()->stock_current)->toBe(95)
        ->and($lebih->refresh()->stock_current)->toBe(43)
        ->and($opname->refresh()->status)->toBe(OpnameStatus::Selesai)
        ->and($opname->posted_at)->not->toBeNull();
});

it('tidak menghasilkan mutasi untuk barang tanpa selisih', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $cocok = Item::factory()->create(['stock_current' => 100]);
    $selisih = Item::factory()->create(['stock_current' => 50]);

    $opname = drafDenganHasilCacah($petugas, [
        [$cocok, 100, null, null],
        [$selisih, 47, AlasanSelisih::BarangHilang, 'Tidak ditemukan.'],
    ]);

    OpnamePoster::post($opname, $petugas);

    expect(StockMutation::where('item_id', $cocok->item_id)->count())->toBe(0)
        ->and(StockMutation::where('item_id', $selisih->item_id)->count())->toBe(1);

    // Penyesuaian nol tetap dicatat pada rincian, hanya tidak jadi mutasi.
    $detailCocok = $opname->refresh()->details->firstWhere('item_id', $cocok->item_id);
    expect($detailCocok->adjustment_amount)->toBe(0)
        ->and($detailCocok->stock_at_posting)->toBe(100);
});

it('menautkan mutasi ke dokumen opname lewat relasi polimorfik', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $item = Item::factory()->create(['stock_current' => 100]);

    $opname = drafDenganHasilCacah($petugas, [
        [$item, 92, AlasanSelisih::Kadaluarsa, 'Delapan botol melewati masa pakai.'],
    ]);

    OpnamePoster::post($opname, $petugas);

    $mutation = StockMutation::firstOrFail();

    expect($mutation->mutation_type)->toBe(MutationType::Penyesuaian)
        ->and($mutation->amount)->toBe(-8)
        ->and($mutation->reference_type)->toBe('opname')
        ->and($mutation->reference_id)->toBe($opname->opname_id)
        ->and($mutation->reference_number)->toBe($opname->judul())
        ->and($mutation->user_id)->toBe($petugas->user_id)
        ->and($mutation->sumber())->toBe('Stock Opname')
        ->and($mutation->notes)->toContain('Kadaluarsa')
        ->and($mutation->notes)->toContain('melewati masa pakai')
        ->and($mutation->fresh()->reference)->toBeInstanceOf(StockOpname::class);
});

// Inti dari ADR 0010: yang dikoreksi opname hanyalah kesalahan pencatatan yang
// ditemukan saat mencacah. Transaksi sah setelah pencacahan tidak dibatalkan.
it('hanya menerapkan selisih hasil cacah walau stok berubah setelahnya', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $item = Item::factory()->create(['stock_current' => 100]);

    $opname = drafDenganHasilCacah($petugas, [
        [$item, 95, AlasanSelisih::BarangRusak, 'Lima rim rusak.'],
    ]);

    // Ada penyerahan barang di tengah opname berjalan.
    StockLedger::record($item, MutationType::Keluar, 20);
    expect($item->refresh()->stock_current)->toBe(80);

    OpnamePoster::post($opname, $petugas);

    $detail = $opname->refresh()->details->first();

    // 80 (stok saat posting) + (-5) selisih hasil cacah = 75. Penyerahan 20
    // tetap berlaku; memaksa stok menjadi 95 justru akan membatalkannya.
    expect($item->refresh()->stock_current)->toBe(75)
        ->and($detail->adjustment_amount)->toBe(-5)
        ->and($detail->difference)->toBe(-5)
        ->and($detail->stock_at_posting)->toBe(80)
        ->and($detail->stokBerubahSejakDicacah())->toBeTrue();
});

it('tidak mengubah stok bila pencatatan sudah cocok, walau stok berubah setelahnya', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $item = Item::factory()->create(['stock_current' => 100]);

    // Hasil cacah cocok dengan stok tercatat: tidak ada kesalahan pencatatan.
    $opname = drafDenganHasilCacah($petugas, [[$item, 100, null, null]]);

    StockLedger::record($item, MutationType::BarangRusak, 20);
    expect($item->refresh()->stock_current)->toBe(80);

    OpnamePoster::post($opname, $petugas);

    // Kerusakan yang sah tercatat tidak boleh dibatalkan oleh posting opname.
    expect($item->refresh()->stock_current)->toBe(80)
        ->and($opname->refresh()->details->first()->adjustment_amount)->toBe(0);
});

it('menandai hanya baris yang stoknya benar-benar berubah', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $tenang = Item::factory()->create(['stock_current' => 100]);
    $berubah = Item::factory()->create(['stock_current' => 60]);

    $opname = drafDenganHasilCacah($petugas, [
        [$tenang, 100, null, null],
        [$berubah, 60, null, null],
    ]);

    StockLedger::record($berubah, MutationType::Keluar, 10);

    OpnamePoster::post($opname, $petugas);

    $details = $opname->refresh()->details->keyBy('item_id');

    expect($details[$tenang->item_id]->stokBerubahSejakDicacah())->toBeFalse()
        ->and($details[$berubah->item_id]->stokBerubahSejakDicacah())->toBeTrue();
});

it('menjaga stok akhir sama dengan jumlah seluruh mutasi', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $item = Item::factory()->create(['stock_current' => 0]);

    StockLedger::record($item, MutationType::SaldoAwal, 100);

    $opname = drafDenganHasilCacah($petugas, [
        [$item, 88, AlasanSelisih::KesalahanPencatatan, 'Penerimaan tercatat berlebih.'],
    ]);

    OpnamePoster::post($opname, $petugas);

    expect($item->refresh()->stock_current)
        ->toBe(88)
        ->toBe((int) $item->stockMutations()->sum('amount'));
});

it('mencatat posting ke jejak audit', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $item = Item::factory()->create(['stock_current' => 100]);

    $opname = drafDenganHasilCacah($petugas, [[$item, 100, null, null]]);

    OpnamePoster::post($opname, $petugas);

    expect(AuditLog::where('user_id', $petugas->user_id)
        ->where('activity', 'like', 'Memposting Stok Opname%')
        ->exists())->toBeTrue();
});

it('menolak posting opname yang sudah selesai', function () {
    $petugas = User::factory()->role(UserRole::PengelolaGudang)->create();
    $item = Item::factory()->create(['stock_current' => 100]);

    $opname = drafDenganHasilCacah($petugas, [[$item, 100, null, null]]);
    OpnamePoster::post($opname, $petugas);

    expect(fn () => OpnamePoster::post($opname->refresh(), $petugas))
        ->toThrow(OpnameTidakSiapException::class);
});
