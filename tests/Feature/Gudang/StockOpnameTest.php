<?php

use App\Enums\AlasanSelisih;
use App\Enums\AuditEvent;
use App\Enums\MutationType;
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
use App\Support\StockLedger;

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

/**
 * Jam tertentu hari ini, untuk uji yang membandingkan waktu cacah dengan
 * waktu mutasi.
 */
function pukulOpname(int $jam, int $menit = 0, int $detik = 0): Carbon\CarbonInterface
{
    return today()->setTime($jam, $menit, $detik);
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

// ADR 0057: hanya satu draf boleh berjalan dalam satu waktu.
describe('satu draf aktif', function () {
    it('menolak draf kedua dan mengarahkan ke draf yang masih berjalan', function () {
        Item::factory()->create();
        $actor = petugasGudang();
        $draf = buatDraf($actor);

        $this->actingAs($actor)
            ->post('/opname', opnamePayload(['period_type' => PeriodeOpname::Insidental->value]))
            ->assertRedirect(route('opname.edit', $draf))
            ->assertSessionHas('error', fn (string $pesan) => str_contains($pesan, $draf->judul()));

        expect(StockOpname::count())->toBe(1)
            ->and(AuditLog::where('activity', 'like', 'Membuat draf%')->count())->toBe(1);
    });

    // Periode yang sama tetap boleh diopname berulang (ADR 0012), asal
    // berurutan.
    it('mengizinkan opname baru setelah draf sebelumnya diposting atau dihapus', function () {
        Item::factory()->create(['stock_current' => 10]);
        $actor = petugasGudang();
        $insidental = ['period_type' => PeriodeOpname::Insidental->value];

        $pertama = buatDraf($actor, $insidental);
        $this->actingAs($actor)->put("/opname/{$pertama->opname_id}", ['aksi' => 'posting']);
        expect($pertama->refresh()->isSelesai())->toBeTrue();

        $kedua = buatDraf($actor, $insidental);
        $this->actingAs($actor)->delete("/opname/{$kedua->opname_id}");

        $ketiga = buatDraf($actor, $insidental);

        expect($ketiga->isDraf())->toBeTrue()
            ->and(StockOpname::where('period_type', PeriodeOpname::Insidental)->count())->toBe(2);
    });

    it('menawarkan Lanjut Cacah alih-alih Mulai Opname selama ada draf', function () {
        Item::factory()->create();
        $actor = petugasGudang();

        $this->actingAs($actor)->get('/opname')->assertSee(route('opname.create'), false);
        $this->actingAs($actor)->get('/opname/create')->assertSee('Buat Draf Opname');

        $draf = buatDraf($actor);

        $this->actingAs($actor)->get('/opname')
            ->assertOk()
            ->assertSee(route('opname.edit', $draf), false)
            ->assertDontSee(route('opname.create'), false);

        $this->actingAs($actor)->get('/opname/create')
            ->assertOk()
            ->assertSee($draf->judul())
            ->assertSee(route('opname.edit', $draf), false)
            ->assertDontSee('Buat Draf Opname');
    });
});

describe('menyimpan hasil cacah', function () {
    it('menyimpan sebagian dan tetap berstatus draf', function () {
        $this->travelTo(pukulOpname(10));

        $dicacah = Item::factory()->create(['stock_current' => 100]);
        $belum = Item::factory()->create(['stock_current' => 50]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);

        // Dipilih lewat item_id, bukan urutan koleksi: kode barang dari factory
        // acak sehingga urutan rincian tidak dapat diandalkan.
        $details = $opname->details->keyBy('item_id');

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(pukulOpname(9, 30)),
            'details' => [$details[$dicacah->item_id]->opname_detail_id => ['stock_real' => 100]],
        ])->assertSessionHasNoErrors();

        expect($details[$dicacah->item_id]->refresh()->stock_real)->toBe(100)
            ->and($details[$dicacah->item_id]->difference)->toBe(0)
            // Waktu cacah adalah waktu di lembar kertas, bukan saat disimpan.
            ->and($details[$dicacah->item_id]->counted_at->equalTo(pukulOpname(9, 30)))->toBeTrue()
            // Barang kedua belum dicacah, jadi opname tetap draf.
            ->and($details[$belum->item_id]->refresh()->stock_real)->toBeNull()
            ->and($opname->refresh()->status)->toBe(OpnameStatus::Draf);
    });

    it('menghitung selisih terhadap stok sistem', function () {
        Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(),
            'details' => [$detail->opname_detail_id => [
                'stock_real' => 95,
                'adjustment_reason' => AlasanSelisih::BarangRusak->value,
                'adjustment_note' => 'Lima rim rusak terkena air.',
            ]],
        ])->assertSessionHasNoErrors();

        expect($detail->refresh()->difference)->toBe(-5)
            ->and($detail->adjustment_reason)->toBe(AlasanSelisih::BarangRusak);
    });

    // Kewajiban ini dinilai terhadap pembanding yang dihitung server, bukan
    // nilai yang dikirim, supaya tidak dapat dipalsukan.
    it('mewajibkan alasan dan keterangan bila berselisih', function () {
        Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(),
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
            'dihitung_pada' => dihitungPada(),
            'details' => [$detail->opname_detail_id => ['stock_real' => 100]],
        ])->assertSessionHasNoErrors();
    });
});

// ADR 0057: petugas mencacah di kertas lalu mengetiknya kemudian. Pembanding
// tiap baris adalah stok sistem pada "Dihitung pada" menurut buku mutasi.
describe('dihitung pada', function () {
    beforeEach(function () {
        // 08:00 — draf dibuat dengan stok 5.
        $this->travelTo(pukulOpname(8));

        $this->petugas = petugasGudang();
        $this->barang = Item::factory()->create(['item_code' => 'ATK-501', 'stock_current' => 5]);
        $this->opname = buatDraf($this->petugas);
        $this->baris = $this->opname->details->first();

        // 10:15 — tiga diserahkan; stok sistem menjadi 2.
        $this->travelTo(pukulOpname(10, 15));
        StockLedger::record($this->barang, MutationType::Keluar, 3);

        // 16:00 — lembar kertas diketik.
        $this->travelTo(pukulOpname(16));
    });

    it('membandingkan dengan stok sebelum penyerahan bila barang dihitung sebelumnya', function () {
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => dihitungPada(pukulOpname(8, 30)),
            'details' => [$this->baris->opname_detail_id => ['stock_real' => 5]],
        ])->assertSessionHasNoErrors();

        $this->baris->refresh();

        expect($this->baris->stock_stored)->toBe(5)
            ->and($this->baris->difference)->toBe(0)
            ->and($this->baris->counted_at->equalTo(pukulOpname(8, 30)))->toBeTrue();

        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", ['aksi' => 'posting'])
            ->assertRedirect(route('opname.show', $this->opname));

        // Penyerahan yang sah tidak dibatalkan, dan tidak ada penyesuaian.
        expect($this->barang->refresh()->stock_current)->toBe(2)
            ->and($this->baris->refresh()->adjustment_amount)->toBe(0)
            ->and($this->baris->stokBerubahSejakDicacah())->toBeTrue();
    });

    // Contoh bug yang ditutup ADR 0057: dengan salinan saat draf dibuat,
    // angka 2 ini dulu tercatat "selisih -3" lalu diterapkan sekali lagi.
    it('tidak memunculkan selisih palsu bila barang dihitung sesudah penyerahan', function () {
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'aksi' => 'posting',
            'dihitung_pada' => dihitungPada(pukulOpname(10, 30)),
            'details' => [$this->baris->opname_detail_id => ['stock_real' => 2]],
        ])->assertRedirect(route('opname.show', $this->opname));

        $this->baris->refresh();

        expect($this->baris->stock_stored)->toBe(2)
            ->and($this->baris->difference)->toBe(0)
            ->and($this->baris->adjustment_amount)->toBe(0)
            ->and($this->barang->refresh()->stock_current)->toBe(2);
    });

    it('menghitung mutasi di dalam menit yang sama sebagai sudah terjadi', function () {
        // 10:20:30 — satu lagi diserahkan; stok sistem menjadi 1.
        $this->travelTo(pukulOpname(10, 20, 30));
        StockLedger::record($this->barang, MutationType::Keluar, 1);
        $this->travelTo(pukulOpname(16));

        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => dihitungPada(pukulOpname(10, 20)),
            'details' => [$this->baris->opname_detail_id => ['stock_real' => 1]],
        ])->assertSessionHasNoErrors();

        expect($this->baris->refresh()->stock_stored)->toBe(1)
            ->and($this->baris->difference)->toBe(0);
    });

    it('menilai kewajiban alasan terhadap stok pada waktu cacah, bukan salinan saat draf dibuat', function () {
        // Fisik 5 sesudah penyerahan berarti lebih 3, walau draf mencatat 5.
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => dihitungPada(pukulOpname(11)),
            'details' => [$this->baris->opname_detail_id => ['stock_real' => 5]],
        ])->assertSessionHasErrors("details.{$this->baris->opname_detail_id}.adjustment_reason");

        expect($this->baris->refresh()->stock_real)->toBeNull();
    });

    it('menolak baris yang stok sistem tampilannya sudah berbeda', function () {
        // Halaman dibuka sebelum penyerahan tercatat: layar menampilkan 5.
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => dihitungPada(pukulOpname(11)),
            'hasil_cacah' => json_encode([$this->baris->opname_detail_id => [
                'stock_real' => '5', 'stok_sistem' => '5',
            ]]),
        ])->assertSessionHasErrors([
            "details.{$this->baris->opname_detail_id}.stock_real" => 'Stok sistem ATK-501 pada '
                .pukulOpname(11)->translatedFormat('d M Y H:i').' ternyata 2, bukan 5. Periksa lagi hasil cacahnya.',
        ]);

        expect($this->baris->refresh()->stock_real)->toBeNull();

        // Dengan stok sistem yang benar, barisnya diterima.
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => dihitungPada(pukulOpname(11)),
            'hasil_cacah' => json_encode([$this->baris->opname_detail_id => [
                'stock_real' => '2', 'stok_sistem' => '2',
            ]]),
        ])->assertSessionHasNoErrors();

        expect($this->baris->refresh()->stock_real)->toBe(2);
    });

    it('menghitung ulang pembanding hanya bila angkanya diubah', function () {
        $simpan = fn (int $angka, Carbon\CarbonInterface $waktu) => $this->actingAs($this->petugas)
            ->put("/opname/{$this->opname->opname_id}", [
                'dihitung_pada' => dihitungPada($waktu),
                'details' => [$this->baris->opname_detail_id => ['stock_real' => $angka]],
            ])->assertSessionHasNoErrors();

        $simpan(5, pukulOpname(8, 30));

        // Dikirim ulang dengan angka sama dan jam lain: tidak berubah.
        $simpan(5, pukulOpname(12));
        expect($this->baris->refresh()->stock_stored)->toBe(5)
            ->and($this->baris->counted_at->equalTo(pukulOpname(8, 30)))->toBeTrue();

        // Dicacah ulang dengan angka lain: pembanding dan waktunya baru.
        $simpan(2, pukulOpname(12));
        expect($this->baris->refresh()->stock_stored)->toBe(2)
            ->and($this->baris->difference)->toBe(0)
            ->and($this->baris->counted_at->equalTo(pukulOpname(12)))->toBeTrue();
    });

    it('mewajibkan dihitung pada setiap kali angka Stok Fisik berubah', function () {
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'details' => [$this->baris->opname_detail_id => ['stock_real' => 2]],
        ])->assertSessionHasErrors(['dihitung_pada' => 'Dihitung pada wajib diisi sesuai lembar cacah.']);

        expect($this->baris->refresh()->stock_real)->toBeNull();
    });

    it('tidak mewajibkan dihitung pada untuk mengosongkan baris atau mengubah alasan saja', function () {
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => dihitungPada(pukulOpname(11)),
            'details' => [$this->baris->opname_detail_id => [
                'stock_real' => 1,
                'adjustment_reason' => AlasanSelisih::BarangHilang->value,
                'adjustment_note' => 'Satu tidak ditemukan.',
            ]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'details' => [$this->baris->opname_detail_id => [
                'stock_real' => 1,
                'adjustment_reason' => AlasanSelisih::BarangRusak->value,
                'adjustment_note' => 'Satu rusak, dibuang.',
            ]],
        ])->assertSessionHasNoErrors();

        expect($this->baris->refresh()->adjustment_reason)->toBe(AlasanSelisih::BarangRusak)
            ->and($this->baris->stock_stored)->toBe(2);

        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'details' => [$this->baris->opname_detail_id => ['stock_real' => '']],
        ])->assertSessionHasNoErrors();

        expect($this->baris->refresh()->stock_real)->toBeNull();
    });

    it('menolak waktu sebelum tanggal pencacahan atau sesudah saat ini', function (Closure $waktu, string $pesan) {
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => dihitungPada($waktu()),
            'details' => [$this->baris->opname_detail_id => ['stock_real' => 2]],
        ])->assertSessionHasErrors(['dihitung_pada' => $pesan]);

        expect($this->baris->refresh()->stock_real)->toBeNull();
    })->with([
        'kemarin' => [fn () => pukulOpname(23, 59)->subDay(), 'Dihitung pada tidak boleh sebelum tanggal pencacahan, '.today()->translatedFormat('d F Y').'.'],
        'nanti' => [fn () => pukulOpname(16, 1), 'Dihitung pada tidak boleh melewati waktu sekarang.'],
    ]);

    it('menolak dihitung pada yang tidak terbaca', function () {
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => '09/11/2026 08:30',
            'details' => [$this->baris->opname_detail_id => ['stock_real' => 2]],
        ])->assertSessionHasErrors('dihitung_pada');
    });

    it('menerima waktu cacah sebelum draf dibuat pada tanggal pencacahan', function () {
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => dihitungPada(pukulOpname(7)),
            'details' => [$this->baris->opname_detail_id => ['stock_real' => 5]],
        ])->assertSessionHasNoErrors();

        expect($this->baris->refresh()->stock_stored)->toBe(5);
    });

    it('menyertakan stok kini dan mutasi sejak tanggal cacah di layar pencacahan', function () {
        $this->actingAs($this->petugas)->get("/opname/{$this->opname->opname_id}/edit")
            ->assertOk()
            ->assertSee('Dihitung Pada')
            ->assertSee('name="dihitung_pada"', false)
            ->assertViewHas('mutasi', fn (array $mutasi) => $mutasi[$this->baris->opname_detail_id] === [
                [pukulOpname(10, 15)->format('Y-m-d\TH:i:s'), -3],
            ]);
    });

    it('mencatat waktu cacah di log aktivitas', function () {
        $this->actingAs($this->petugas)->put("/opname/{$this->opname->opname_id}", [
            'dihitung_pada' => dihitungPada(pukulOpname(8, 30)),
            'details' => [$this->baris->opname_detail_id => ['stock_real' => 5]],
        ]);

        expect(AuditLog::where('subject_id', $this->opname->opname_id)
            ->where('event', AuditEvent::Ubah)
            ->value('activity'))->toContain('dihitung pada '.pukulOpname(8, 30)->translatedFormat('d M Y H:i'));
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
            'dihitung_pada' => dihitungPada(),
            'details' => [$detail->opname_detail_id => ['stock_real' => 100]],
        ]);

        expect($opname->refresh()->status)->toBe(OpnameStatus::Selesai);

        $this->actingAs($actor)->get("/opname/{$opname->opname_id}/edit")
            ->assertRedirect(route('opname.show', $opname));

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(),
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

    it('menampilkan tombol Buang Draf hanya bagi yang boleh mengubah', function () {
        Item::factory()->create();

        $actor = petugasGudang();
        $opname = buatDraf($actor);

        $this->actingAs($actor)->get("/opname/{$opname->opname_id}/edit")->assertOk()->assertSee('Buang Draf');
        $this->actingAs($actor)->get("/opname/{$opname->opname_id}")->assertOk()->assertSee('Buang Draf');

        // Kasubbag hanya membaca (ADR 0041).
        $this->actingAs(User::factory()->role(UserRole::KasubbagUmumTi)->create())
            ->get("/opname/{$opname->opname_id}")
            ->assertOk()
            ->assertDontSee('Buang Draf');
    });

    it('membuang notifikasi milik draf yang dibuang', function () {
        Item::factory()->create(['stock_current' => 10]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        // Seluruh barang diisi → keadaan "siap diposting" menunjuk draf ini.
        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(),
            'details' => [$detail->opname_detail_id => ['stock_real' => 10]],
        ]);
        NotificationScanner::periksaOpname();

        expect(Notification::where('subject_id', $opname->opname_id)->exists())->toBeTrue();

        $this->actingAs($actor)->delete("/opname/{$opname->opname_id}")->assertRedirect('/opname');

        expect(Notification::where('subject_id', $opname->opname_id)->exists())->toBeFalse();
    });
});

// ADR 0057: opname berkala wajib mengisi setiap barang.
describe('opname berkala wajib diisi semua', function () {
    it('tidak memposting selama ada barang kosong, tetapi menyimpan isiannya', function () {
        $diisi = Item::factory()->create(['stock_current' => 10]);
        $kosong = Item::factory()->create(['stock_current' => 7]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $details = $opname->details->keyBy('item_id');

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'aksi' => 'posting',
            'dihitung_pada' => dihitungPada(),
            'hasil_cacah' => json_encode([$details[$diisi->item_id]->opname_detail_id => ['stock_real' => '10']]),
        ])
            ->assertRedirect(route('opname.edit', $opname))
            ->assertSessionHas('error', 'Masih ada 1 barang yang belum diisi. Lengkapi seluruh hasil cacah sebelum memposting.');

        expect($opname->refresh()->status)->toBe(OpnameStatus::Draf)
            ->and($details[$diisi->item_id]->refresh()->stock_real)->toBe(10)
            ->and($details[$kosong->item_id]->refresh()->stock_real)->toBeNull();
    });

    it('menampilkan "Belum Diisi" tanpa petunjuk mengikuti stok sistem di layar', function () {
        Item::factory()->create(['item_code' => 'ZZZ-901', 'item_name' => 'Map Arsip Biru', 'stock_current' => 12]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);

        $this->actingAs($actor)->get("/opname/{$opname->opname_id}/edit")
            ->assertOk()
            ->assertSee('Map Arsip Biru')
            ->assertSee('Belum Diisi')
            ->assertDontSee('Barang yang dikosongkan dianggap sesuai dan')
            ->assertDontSee('Ikut Stok Sistem');
    });
});

// ADR 0056, sejak ADR 0057 hanya untuk opname insidental: petugas cukup
// mengisi barang yang berbeda; yang kosong mengikuti stok sistem saat
// diposting.
describe('opname insidental: kosong mengikuti stok sistem', function () {
    it('menampilkan petunjuk dan baris pencacahan di layar', function () {
        Item::factory()->create(['item_code' => 'ZZZ-901', 'item_name' => 'Map Arsip Biru', 'stock_current' => 12]);

        $actor = petugasGudang();
        $opname = buatDraf($actor, ['period_type' => PeriodeOpname::Insidental->value]);

        $this->actingAs($actor)->get("/opname/{$opname->opname_id}/edit")
            ->assertOk()
            ->assertSee('Barang yang dikosongkan dianggap sesuai dan')
            ->assertSee('Ikut Stok Sistem')
            ->assertSee('Map Arsip Biru')
            ->assertSee('name="hasil_cacah"', false)
            ->assertDontSee('Simpan draf sebelum memposting');
    });

    it('menyimpan isian dari JSON dan posting dalam satu kiriman', function () {
        $berbeda = Item::factory()->create(['stock_current' => 100]);
        $kosong = Item::factory()->create(['stock_current' => 30]);

        $actor = petugasGudang();
        $opname = buatDraf($actor, ['period_type' => PeriodeOpname::Insidental->value]);
        $details = $opname->details->keyBy('item_id');

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'aksi' => 'posting',
            'dihitung_pada' => dihitungPada(),
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
            ->and($details[$kosong->item_id]->stock_stored)->toBe(30)
            ->and($details[$berbeda->item_id]->refresh()->diisiPetugas())->toBeTrue();

        // Simpan dan posting masing-masing berjejak.
        expect(AuditLog::where('subject_id', $opname->opname_id)->where('event', AuditEvent::Ubah)->count())->toBe(1)
            ->and(AuditLog::where('subject_id', $opname->opname_id)->where('event', AuditEvent::Posting)->count())->toBe(1);
    });

    it('mengingatkan draf yang menggantung walau belum ada barang diisi', function () {
        Item::factory()->create();

        $actor = petugasGudang();
        $opname = buatDraf($actor, ['period_type' => PeriodeOpname::Insidental->value]);

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

describe('menyimpan dari layar pencacahan', function () {
    it('tidak menyimpan maupun memposting bila ada selisih tanpa alasan', function () {
        $item = Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'aksi' => 'posting',
            'dihitung_pada' => dihitungPada(),
            'hasil_cacah' => json_encode([$detail->opname_detail_id => ['stock_real' => '90']]),
        ])->assertSessionHasErrors("details.{$detail->opname_detail_id}.adjustment_reason");

        expect($opname->refresh()->status)->toBe(OpnameStatus::Draf)
            ->and($detail->refresh()->stock_real)->toBeNull()
            ->and($item->refresh()->stock_current)->toBe(100);
    });

    it('mengosongkan baris kembali', function () {
        Item::factory()->create(['stock_current' => 100]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        $detail = $opname->details->first();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(),
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
            'dihitung_pada' => dihitungPada(),
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
            'dihitung_pada' => dihitungPada(),
            'details' => [$pertama->opname_detail_id => ['stock_real' => 10]],
        ]);
        $waktuCacah = $pertama->refresh()->counted_at;

        $this->travel(5)->minutes();

        $logSebelum = AuditLog::where('event', AuditEvent::Ubah)->count();

        // Baris kedua diisi; baris pertama dikirim ulang dengan nilai sama.
        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(),
            'hasil_cacah' => json_encode([
                $pertama->opname_detail_id => ['stock_real' => '10'],
                $kedua->opname_detail_id => ['stock_real' => '10'],
            ]),
        ])->assertSessionHasNoErrors();

        // Waktu cacah adalah waktu untuk angka itu, bukan saat disimpan ulang.
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
});

// ADR 0057 memulihkan "Opname Siap Diposting" untuk opname yang mewajibkan
// setiap barang diisi.
describe('notifikasi siap diposting', function () {
    it('memberi tahu pembuat bahwa opname berkala siap diposting, lalu menutupnya saat diposting', function () {
        Item::factory()->count(2)->create(['stock_current' => 10]);

        $actor = petugasGudang();
        $opname = buatDraf($actor);
        [$pertama, $kedua] = $opname->details->values()->all();

        $opname->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(),
            'details' => [$pertama->opname_detail_id => ['stock_real' => 10]],
        ]);
        NotificationScanner::periksaOpname();

        expect(Notification::where('type', NotificationType::OpnameSiapDiposting)->exists())->toBeFalse()
            ->and(Notification::where('type', NotificationType::OpnameDrafMenggantung)->exists())->toBeTrue();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(),
            'details' => [$kedua->opname_detail_id => ['stock_real' => 10]],
        ]);
        NotificationScanner::periksaOpname();

        // Saling eksklusif dengan "menggantung": satu ajakan yang jelas.
        expect(Notification::where('type', NotificationType::OpnameSiapDiposting)
            ->where('user_id', $actor->user_id)
            ->where('subject_id', $opname->opname_id)
            ->exists())->toBeTrue()
            ->and(Notification::where('type', NotificationType::OpnameDrafMenggantung)->exists())->toBeFalse();

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", ['aksi' => 'posting']);
        NotificationScanner::periksaOpname();

        expect(Notification::where('type', NotificationType::OpnameSiapDiposting)->exists())->toBeFalse();
    });

    it('tidak memberi tahu siap diposting pada opname insidental', function () {
        Item::factory()->create(['stock_current' => 10]);

        $actor = petugasGudang();
        $opname = buatDraf($actor, ['period_type' => PeriodeOpname::Insidental->value]);

        $this->actingAs($actor)->put("/opname/{$opname->opname_id}", [
            'dihitung_pada' => dihitungPada(),
            'details' => [$opname->details->first()->opname_detail_id => ['stock_real' => 10]],
        ]);
        NotificationScanner::periksaOpname();

        expect($opname->refresh()->siapDiposting())->toBeFalse()
            ->and(Notification::where('type', NotificationType::OpnameSiapDiposting)->exists())->toBeFalse();
    });
});

describe('daftar dan penyaringan', function () {
    // Diperiksa lewat URL detail tiap opname, bukan label periode: label yang
    // sama juga muncul pada dropdown penyaring sehingga tidak membedakan baris.
    // Satu draf dalam satu waktu (ADR 0057), jadi yang lama diposting dulu.
    it('menyaring berdasarkan periode, tahun, dan status', function () {
        Item::factory()->create();
        $actor = petugasGudang();

        $lama = buatDraf($actor, ['period_type' => PeriodeOpname::Insidental->value, 'period_year' => 2025]);
        $this->actingAs($actor)->put("/opname/{$lama->opname_id}", ['aksi' => 'posting']);
        $baru = buatDraf($actor, ['period_type' => PeriodeOpname::TriwulanIII->value, 'period_year' => 2026]);

        // Dicocokkan sebagai href utuh: tombol "Lanjut Cacah" di kepala
        // halaman menautkan …/edit draf, yang diawali URL detailnya.
        $urlLama = 'href="'.route('opname.show', $lama).'"';
        $urlBaru = 'href="'.route('opname.show', $baru).'"';

        $this->actingAs($actor)->get('/opname?period='.PeriodeOpname::Insidental->value)
            ->assertSee($urlLama, false)->assertDontSee($urlBaru, false);

        $this->actingAs($actor)->get('/opname?year=2026')
            ->assertSee($urlBaru, false)->assertDontSee($urlLama, false);

        $this->actingAs($actor)->get('/opname?status='.OpnameStatus::Selesai->value)
            ->assertSee($urlLama, false)
            ->assertDontSee($urlBaru, false);

        $this->actingAs($actor)->get('/opname?status='.OpnameStatus::Draf->value.'&year=2025')
            ->assertSee('Belum ada stok opname')
            ->assertDontSee($urlLama, false);
    });
});

describe('kiriman ulang', function () {
    it('tidak membuat draf opname kedua saat formulir yang sama dikirim ulang', function () {
        Item::factory()->create();
        $actor = petugasGudang();
        $payload = opnamePayload();

        $this->actingAs($actor)->post('/opname', $payload);
        $opname = StockOpname::sole();

        // Token yang sudah terpakai diperiksa lebih dulu daripada aturan satu
        // draf, jadi kiriman ulang tetap diarahkan ke draf yang dibuatnya.
        $this->actingAs($actor)->post('/opname', $payload)
            ->assertRedirect(route('opname.show', $opname))
            ->assertSessionHas('status');

        expect(StockOpname::count())->toBe(1);
    });
});
