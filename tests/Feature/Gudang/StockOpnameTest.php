<?php

use App\Enums\AlasanSelisih;
use App\Enums\AuditEvent;
use App\Enums\NotificationType;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\NotificationScanner;

function petugasGudang(array $attributes = []): User
{
    return User::factory()->role(UserRole::PengelolaGudang)->create($attributes);
}

function opnamePayload(array $overrides = []): array
{
    return array_merge([
        'submission_token' => tokenKiriman(),
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
            'aksi' => 'posting',
            'details' => [$detail->opname_detail_id => ['stock_real' => 100]],
        ]);

        expect($opname->refresh()->status)->toBe(OpnameStatus::Selesai);

        $this->actingAs($actor)->get("/opname/{$opname->opname_id}/edit")
            ->assertRedirect(route('opname.show', $opname));

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$detail->opname_detail_id => ['stock_real' => 5]],
        ])->assertSessionHas('error');

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", ['aksi' => 'posting'])
            ->assertSessionHas('error');

        // Rute posting lama sudah tidak ada (ADR 0056).
        $this->actingAs($actor)->post("/opname/{$opname->opname_id}/posting")
            ->assertNotFound();

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

// ADR 0056: petugas cukup mengisi barang yang berbeda; yang kosong mengikuti
// stok sistem saat diposting. Layar pencacahan mengirim baris yang berubah
// sebagai satu JSON di `hasil_cacah`.
describe('kosong mengikuti stok sistem', function () {
    it('menampilkan petunjuk dan baris pencacahan di layar', function () {
        Item::factory()->create(['item_code' => 'ZZZ-901', 'item_name' => 'Map Arsip Biru', 'stock_current' => 12]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);

        $this->actingAs($actor)->get("/opname/{$opname->opname_id}/edit")
            ->assertOk()
            ->assertSee('Barang yang dikosongkan dianggap sesuai dan')
            ->assertSee('Map Arsip Biru')
            ->assertSee('name="hasil_cacah"', false)
            ->assertDontSee('Simpan draf sebelum memposting');
    });

    it('menyimpan isian dari JSON dan posting dalam satu kiriman', function () {
        $berbeda = Item::factory()->create(['stock_current' => 100]);
        $kosong = Item::factory()->create(['stock_current' => 30]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $details = $opname->details->keyBy('item_id');

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'aksi' => 'posting',
            'hasil_cacah' => json_encode([
                $details[$berbeda->item_id]->opname_detail_id => [
                    'stock_real' => '97',
                    'adjustment_reason' => AlasanSelisih::BarangRusak->value,
                    'adjustment_note' => 'Tiga rim lembap.',
                ],
            ]),
        ])->assertRedirect(route('opname.show', $opname));

        expect($opname->refresh()->status)->toBe(OpnameStatus::Selesai)
            ->and($berbeda->refresh()->stock_current)->toBe(97)
            ->and($kosong->refresh()->stock_current)->toBe(30)
            ->and($details[$kosong->item_id]->refresh()->mengikutiStokSistem())->toBeTrue()
            ->and($details[$berbeda->item_id]->refresh()->diisiPetugas())->toBeTrue();

        // Simpan dan posting masing-masing berjejak.
        expect(AuditLog::where('subject_id', $opname->opname_id)->where('event', AuditEvent::Ubah)->count())->toBe(1)
            ->and(AuditLog::where('subject_id', $opname->opname_id)->where('event', AuditEvent::Posting)->count())->toBe(1);
    });

    it('tidak menyimpan maupun memposting bila ada selisih tanpa alasan', function () {
        $item = Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'aksi' => 'posting',
            'hasil_cacah' => json_encode([$detail->opname_detail_id => ['stock_real' => '90']]),
        ])->assertSessionHasErrors("details.{$detail->opname_detail_id}.adjustment_reason");

        expect($opname->refresh()->status)->toBe(OpnameStatus::Draf)
            ->and($detail->refresh()->stock_real)->toBeNull()
            ->and($item->refresh()->stock_current)->toBe(100);
    });

    it('mengosongkan baris kembali menjadi mengikuti stok sistem', function () {
        Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$detail->opname_detail_id => [
                'stock_real' => 90,
                'adjustment_reason' => AlasanSelisih::BarangHilang->value,
                'adjustment_note' => 'Sepuluh hilang.',
            ]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'hasil_cacah' => json_encode([$detail->opname_detail_id => [
                'stock_real' => '', 'adjustment_reason' => '', 'adjustment_note' => '',
            ]]),
        ])->assertSessionHasNoErrors();

        $detail->refresh();

        expect($detail->stock_real)->toBeNull()
            ->and($detail->difference)->toBeNull()
            ->and($detail->counted_at)->toBeNull()
            ->and($detail->adjustment_reason)->toBeNull()
            ->and($detail->adjustment_note)->toBeNull();
    });

    it('membuang alasan pada baris yang kembali sesuai', function () {
        Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$detail->opname_detail_id => [
                'stock_real' => 100,
                'adjustment_reason' => AlasanSelisih::BarangRusak->value,
                'adjustment_note' => 'Sisa isian lama.',
            ]],
        ])->assertSessionHasNoErrors();

        expect($detail->refresh()->adjustment_reason)->toBeNull()
            ->and($detail->adjustment_note)->toBeNull();
    });

    it('tidak mengubah baris yang tidak dikirim dan tidak mencatat kiriman tanpa perubahan', function () {
        Item::factory()->count(2)->create(['stock_current' => 10]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        [$pertama, $kedua] = $opname->details->values()->all();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'details' => [$pertama->opname_detail_id => ['stock_real' => 10]],
        ]);
        $waktuCacah = $pertama->refresh()->counted_at;

        $this->travel(5)->minutes();

        $logSebelum = AuditLog::where('event', AuditEvent::Ubah)->count();

        // Baris kedua diisi; baris pertama dikirim ulang dengan nilai sama.
        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'hasil_cacah' => json_encode([
                $pertama->opname_detail_id => ['stock_real' => '10'],
                $kedua->opname_detail_id => ['stock_real' => '10'],
            ]),
        ])->assertSessionHasNoErrors();

        // Waktu cacah adalah saat angka dimasukkan, bukan saat disimpan ulang.
        expect($pertama->refresh()->counted_at->equalTo($waktuCacah))->toBeTrue()
            ->and($kedua->refresh()->stock_real)->toBe(10)
            ->and(AuditLog::where('event', AuditEvent::Ubah)->count())->toBe($logSebelum + 1);

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", ['hasil_cacah' => '{}'])
            ->assertSessionHas('status', 'Tidak ada perubahan untuk disimpan.');

        expect(AuditLog::where('event', AuditEvent::Ubah)->count())->toBe($logSebelum + 1);
    });

    it('menolak kiriman JSON yang tidak terbaca', function () {
        Item::factory()->create();

        $actor = petugasGudang();
        $opname = buatDraf($actor);

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'aksi' => 'posting',
            'hasil_cacah' => '{rusak',
        ])->assertSessionHasErrors('details');

        expect($opname->refresh()->status)->toBe(OpnameStatus::Draf);
    });

    it('mengingatkan draf yang menggantung walau belum ada barang diisi', function () {
        Item::factory()->create();

        $actor = petugasGudang();
        $opname = buatDraf($actor);

        NotificationScanner::periksaOpname();
        expect(Notification::where('type', NotificationType::OpnameDrafMenggantung)->exists())->toBeFalse();

        $opname->forceFill(['created_at' => now()->subDays(8)])->save();
        NotificationScanner::periksaOpname();

        expect(Notification::where('type', NotificationType::OpnameDrafMenggantung)
            ->where('user_id', $actor->user_id)
            ->where('subject_id', $opname->opname_id)
            ->exists())->toBeTrue();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", ['aksi' => 'posting']);
        NotificationScanner::periksaOpname();

        expect(Notification::where('type', NotificationType::OpnameDrafMenggantung)->exists())->toBeFalse();
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

describe('kiriman ulang', function () {
    it('tidak membuat draf opname kedua saat formulir yang sama dikirim ulang', function () {
        Item::factory()->create();
        $actor = petugasGudang();
        $payload = opnamePayload();

        $this->actingAs($actor)->post('/opname', $payload);
        $opname = StockOpname::sole();

        $this->actingAs($actor)->post('/opname', $payload)
            ->assertRedirect(route('opname.show', $opname));

        expect(StockOpname::count())->toBe(1);
    });
});
