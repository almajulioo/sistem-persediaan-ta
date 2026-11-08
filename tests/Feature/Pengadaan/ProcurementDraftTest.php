<?php

use App\Enums\AlasanSelisih;
use App\Enums\MutationType;
use App\Enums\NotificationType;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\ProcurementRequest;
use App\Models\ProcurementRequestDetail;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use App\Support\OpnamePoster;
use App\Support\ProcurementDraftScanner;
use App\Support\ProcurementReceiptPoster;
use Illuminate\Support\Facades\Artisan;

function mutasiManual(User $actor, Item $item, MutationType $type, int $amount): void
{
    test()->actingAs($actor)->post('/mutasi', [
        'submission_token' => tokenKiriman(),
        'item_id' => $item->item_id,
        'mutation_type' => $type->value,
        'amount' => $amount,
        'mutation_date' => now()->format('Y-m-d'),
        'notes' => 'Uji rekonsiliasi draf usulan.',
    ])->assertSessionDoesntHaveErrors();
}

/**
 * Rakit dan posting satu opname langsung lewat model, sejajar
 * `drafDenganHasilCacah()` di StockMutationTest.php tetapi diberi nama
 * berbeda karena nama pembantu global harus unik antar berkas uji.
 */
function postingOpnameMenurunkanStok(User $petugas, Item $item, int $stokFisik): StockOpname
{
    $opname = StockOpname::create([
        'opname_date' => now(),
        'user_id' => $petugas->user_id,
        'status' => OpnameStatus::Draf,
        'period_type' => PeriodeOpname::TriwulanIII,
        'period_year' => now()->year,
    ]);

    $opname->details()->create([
        'item_id' => $item->item_id,
        'stock_stored' => $item->stock_current,
        'stock_real' => $stokFisik,
        'difference' => $stokFisik - $item->stock_current,
        'counted_at' => now(),
        'adjustment_reason' => AlasanSelisih::BarangRusak,
        'adjustment_note' => 'Ditemukan rusak saat pencacahan.',
    ]);

    OpnamePoster::post($opname->fresh('details'), $petugas);

    return $opname->fresh('details');
}

/**
 * Seed draf langsung lewat scanner, tanpa lapisan HTTP — dipakai uji yang
 * fokus pada aksi controller draf, bukan pada pemicu reaktifnya sendiri.
 */
/**
 * Id draf yang sedang ada, seperti yang dibawa halaman draf di `draft_id`
 * (ADR 0045). String kosong bila tidak ada draf.
 */
function idDrafUsulan(): string
{
    return (string) ProcurementRequest::query()->draf()->value('procurement_request_id');
}

function seedDraf(): Item
{
    $item = Item::factory()->habis()->create();
    ProcurementDraftScanner::perbaruiSeluruh();

    return $item;
}

/**
 * Barang di bawah minimum yang sudah dipindai ke draf, untuk uji ADR 0047.
 * Ambangnya eksplisit, bukan `habis()`, karena uji penerimaan di bawah
 * menghitung saran jumlahnya.
 */
function barangKurangDiDraf(int $stok = 0, int $min = 10, int $max = 50): Item
{
    $item = Item::factory()->create(['stock_current' => $stok, 'stock_min' => $min, 'stock_max' => $max]);
    ProcurementDraftScanner::perbaruiSeluruh();

    return $item;
}

function barisDrafUntuk(Item $item): ?ProcurementRequestDetail
{
    return ProcurementRequestDetail::query()
        ->where('item_id', $item->item_id)
        ->whereHas('procurementRequest', fn ($query) => $query->draf())
        ->first();
}

describe('hak akses draf', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        auth()->logout();

        $this->get('/usulan-pengadaan/draf')->assertRedirect('/login');
        $this->put('/usulan-pengadaan/draf', ['items' => []])->assertRedirect('/login');
        $this->post('/usulan-pengadaan/draf/ajukan')->assertRedirect('/login');
        $this->delete('/usulan-pengadaan/draf')->assertRedirect('/login');
    });

    it('menolak Pejabat Pengadaan mengakses draf', function () {
        seedDraf();

        $this->actingAs(pejabatPengadaan())->get('/usulan-pengadaan/draf')->assertForbidden();
        $this->actingAs(pejabatPengadaan())->put('/usulan-pengadaan/draf', ['items' => []])->assertForbidden();
        $this->actingAs(pejabatPengadaan())->post('/usulan-pengadaan/draf/ajukan')->assertForbidden();
        $this->actingAs(pejabatPengadaan())->delete('/usulan-pengadaan/draf')->assertForbidden();
    });

    it('mengizinkan Pengelola Gudang membuka dan mengelola draf', function () {
        $item = seedDraf();

        $this->actingAs(pengelolaGudang())->get('/usulan-pengadaan/draf')->assertOk();

        $this->actingAs(pengelolaGudang())
            ->put('/usulan-pengadaan/draf', [
                'draft_id' => idDrafUsulan(),
                'items' => [['item_id' => $item->item_id, 'amount_request' => 5]],
            ])
            ->assertRedirect('/usulan-pengadaan/draf');
    });

    it('mengizinkan Admin membuka dan mengelola draf', function () {
        seedDraf();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/usulan-pengadaan/draf')
            ->assertOk();
    });
});

describe('rekonsiliasi draf', function () {
    it('membuat draf saat mutasi manual menjatuhkan stok ke bawah minimum', function () {
        $actor = pengelolaGudang();
        $item = Item::factory()->create(['stock_current' => 20, 'stock_min' => 10, 'stock_max' => 50]);

        mutasiManual($actor, $item, MutationType::BarangRusak, 15);

        $draft = ProcurementRequest::query()->draf()->with('details')->firstOrFail();

        expect($draft->details)->toHaveCount(1)
            ->and($draft->details->first()->item_id)->toBe($item->item_id)
            ->and($draft->details->first()->amount_request)->toBe(45)
            ->and($draft->details->first()->is_auto)->toBeTrue()
            ->and($draft->details->first()->source)->toBe(ProcurementRequestSource::PemantauanStok);
    });

    it('menggabungkan barang kedua yang jatuh di bawah minimum ke draf yang sama', function () {
        $actor = pengelolaGudang();
        $itemA = Item::factory()->create(['stock_current' => 20, 'stock_min' => 10, 'stock_max' => 50]);
        $itemB = Item::factory()->create(['stock_current' => 8, 'stock_min' => 5, 'stock_max' => 0]);

        mutasiManual($actor, $itemA, MutationType::BarangRusak, 15);
        mutasiManual($actor, $itemB, MutationType::BarangRusak, 5);

        expect(ProcurementRequest::query()->draf()->count())->toBe(1);

        $draft = ProcurementRequest::query()->draf()->with('details')->firstOrFail();
        expect($draft->details)->toHaveCount(2);
    });

    it('membuang baris dan menghapus draf begitu satu-satunya barang pulih', function () {
        $actor = pengelolaGudang();
        $item = Item::factory()->create(['stock_current' => 20, 'stock_min' => 10, 'stock_max' => 50]);

        mutasiManual($actor, $item, MutationType::BarangRusak, 15);
        $draftId = ProcurementRequest::query()->draf()->firstOrFail()->procurement_request_id;

        mutasiManual($actor, $item, MutationType::HibahMasuk, 50);

        expect(ProcurementRequest::whereKey($draftId)->doesntExist())->toBeTrue()
            ->and(ProcurementRequest::query()->draf()->doesntExist())->toBeTrue();
    });

    it('mempertahankan perubahan manual pada baris otomatis setelah pindai ulang', function () {
        $item = seedDraf();

        $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', [
            'draft_id' => idDrafUsulan(),
            'items' => [['item_id' => $item->item_id, 'amount_request' => 7]],
        ]);

        ProcurementDraftScanner::perbaruiSeluruh();

        $detail = ProcurementRequest::query()->draf()->with('details')->firstOrFail()->details->first();
        expect($detail->amount_request)->toBe(7)
            ->and($detail->is_auto)->toBeFalse();
    });

    it('mempertahankan barang yang ditambahkan manual meski tidak di bawah minimum', function () {
        $itemOtomatis = seedDraf();
        $itemManual = Item::factory()->create(['stock_current' => 100, 'stock_min' => 10, 'stock_max' => 0]);

        $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', [
            'draft_id' => idDrafUsulan(),
            'items' => [
                ['item_id' => $itemOtomatis->item_id, 'amount_request' => 10],
                ['item_id' => $itemManual->item_id, 'amount_request' => 3],
            ],
        ]);

        ProcurementDraftScanner::perbaruiSeluruh();

        $draft = ProcurementRequest::query()->draf()->with('details')->firstOrFail();
        expect($draft->details->pluck('item_id'))->toContain($itemManual->item_id);
    });

    it('membuat draf baru setelah draf lama dibuang, bukan menghidupkan yang lama', function () {
        $item = seedDraf();
        $draftLamaId = ProcurementRequest::query()->draf()->firstOrFail()->procurement_request_id;

        $this->actingAs(pengelolaGudang())->delete('/usulan-pengadaan/draf', ['draft_id' => idDrafUsulan()]);

        ProcurementDraftScanner::perbaruiSeluruh();

        $draftBaru = ProcurementRequest::query()->draf()->firstOrFail();
        expect($draftBaru->procurement_request_id)->not->toBe($draftLamaId);
    });

    it('membuat draf baru setelah draf dikosongkan lewat update, bukan hanya dibuang', function () {
        $item = seedDraf();

        $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', ['draft_id' => idDrafUsulan(), 'items' => []]);

        expect(ProcurementRequest::query()->draf()->doesntExist())->toBeTrue();

        ProcurementDraftScanner::perbaruiSeluruh();

        expect(ProcurementRequest::query()->draf()->exists())->toBeTrue();
    });

    it('membuang baris draf saat barangnya diarsipkan', function () {
        $item = seedDraf();

        $this->actingAs(pengelolaGudang())->patch("/barang/{$item->item_id}/status");

        expect(ProcurementRequest::query()->draf()->doesntExist())->toBeTrue();
    });

    it('memicu rekonsiliasi saat posting stok opname menjatuhkan barang di bawah minimum', function () {
        $petugas = pengelolaGudang();
        $item = Item::factory()->create(['stock_current' => 100, 'stock_min' => 10, 'stock_max' => 200]);

        postingOpnameMenurunkanStok($petugas, $item, 5);

        $draft = ProcurementRequest::query()->draf()->with('details')->firstOrFail();
        expect($draft->details->pluck('item_id'))->toContain($item->item_id);
    });

    it('menangkap barang di bawah minimum lewat sapuan terjadwal tanpa pemicu reaktif', function () {
        $item = Item::factory()->habis()->create();

        Artisan::call('notifikasi:periksa');

        $draft = ProcurementRequest::query()->draf()->with('details')->firstOrFail();
        expect($draft->details->pluck('item_id'))->toContain($item->item_id);
    });
});

describe('sumber per barang di draf', function () {
    it('mempertahankan sumber pemantauan stok setelah jumlahnya disunting', function () {
        $item = seedDraf();

        $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', [
            'draft_id' => idDrafUsulan(),
            'items' => [['item_id' => $item->item_id, 'amount_request' => 7]],
        ]);

        $detail = ProcurementRequest::query()->draf()->with('details')->firstOrFail()->details->sole();

        expect($detail->is_auto)->toBeFalse()
            ->and($detail->source)->toBe(ProcurementRequestSource::PemantauanStok);
    });

    it('mencatat barang tambahan sebagai Manual beserta keterangannya', function () {
        $itemOtomatis = seedDraf();
        $itemManual = Item::factory()->create(['stock_current' => 100, 'stock_min' => 10, 'stock_max' => 0]);

        $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', [
            'draft_id' => idDrafUsulan(),
            'items' => [
                ['item_id' => $itemOtomatis->item_id, 'amount_request' => 10],
                ['item_id' => $itemManual->item_id, 'amount_request' => 3, 'note' => 'Persiapan rapat kerja'],
            ],
        ])->assertSessionHasNoErrors();

        $details = ProcurementRequest::query()->draf()->with('details')->firstOrFail()->details->keyBy('item_id');

        expect($details[$itemManual->item_id]->source)->toBe(ProcurementRequestSource::Manual)
            ->and($details[$itemManual->item_id]->note)->toBe('Persiapan rapat kerja')
            ->and($details[$itemOtomatis->item_id]->source)->toBe(ProcurementRequestSource::PemantauanStok);
    });

    it('memperbarui dan mengosongkan keterangan barang manual', function () {
        seedDraf();
        $item = Item::factory()->create(['stock_current' => 100, 'stock_min' => 10, 'stock_max' => 0]);

        $kirim = fn (?string $note) => $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', [
            'draft_id' => idDrafUsulan(),
            'items' => [['item_id' => $item->item_id, 'amount_request' => 3, 'note' => $note]],
        ]);

        $kirim('Alasan awal');
        $kirim('Alasan yang dibetulkan');
        expect(ProcurementRequestDetail::where('item_id', $item->item_id)->sole()->note)->toBe('Alasan yang dibetulkan');

        $kirim('');
        expect(ProcurementRequestDetail::where('item_id', $item->item_id)->sole()->note)->toBeNull();
    });

    it('mengabaikan keterangan dan sumber kiriman pada baris yang bukan manual', function () {
        $item = seedDraf();

        $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', [
            'draft_id' => idDrafUsulan(),
            'items' => [[
                'item_id' => $item->item_id,
                'amount_request' => 7,
                'source' => ProcurementRequestSource::Manual->value,
                'note' => 'Tidak boleh tersimpan',
            ]],
        ]);

        $detail = ProcurementRequestDetail::where('item_id', $item->item_id)->sole();

        expect($detail->source)->toBe(ProcurementRequestSource::PemantauanStok)
            ->and($detail->note)->toBeNull();
    });

    it('menampilkan sumber tiap barang di halaman draf', function () {
        seedDraf();

        // Direktif di atribut komponen Blade tidak dikompilasi dan lolos
        // mentah ke Alpine sebagai ekspresi yang rusak.
        $this->actingAs(pengelolaGudang())
            ->get('/usulan-pengadaan/draf')
            ->assertOk()
            ->assertSee(ProcurementRequestSource::PemantauanStok->label())
            ->assertDontSee('@js(', false);
    });

    it('membawa sumber tiap barang ke usulan yang diajukan', function () {
        $itemOtomatis = seedDraf();
        $itemManual = Item::factory()->create(['stock_current' => 100, 'stock_min' => 10, 'stock_max' => 0]);

        $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', [
            'draft_id' => idDrafUsulan(),
            'items' => [
                ['item_id' => $itemOtomatis->item_id, 'amount_request' => 10],
                ['item_id' => $itemManual->item_id, 'amount_request' => 3, 'note' => 'Kebutuhan kegiatan'],
            ],
        ]);
        $this->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => idDrafUsulan()]);

        $usulan = ProcurementRequest::query()->bukanDraf()->sole();

        expect(collect($usulan->jumlahPerSumber())->map(fn ($baris) => [$baris['sumber'], $baris['jumlah']])->all())
            ->toBe([[ProcurementRequestSource::Manual, 1], [ProcurementRequestSource::PemantauanStok, 1]]);

        $this->get("/usulan-pengadaan/{$usulan->procurement_request_id}")
            ->assertOk()
            ->assertSee(ProcurementRequestSource::PemantauanStok->label())
            ->assertSee('Kebutuhan kegiatan')
            ->assertSee('Saring sumber', false);
    });
});

describe('halaman draf yang usang', function () {
    it('tidak membuat draf baru saat halaman draf yang sudah diajukan disimpan ulang', function () {
        $item = seedDraf();
        $idLama = idDrafUsulan();

        $this->actingAs(pengelolaGudang())->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => $idLama]);

        $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', [
            'draft_id' => $idLama,
            'items' => [['item_id' => $item->item_id, 'amount_request' => 99]],
        ])->assertRedirect("/usulan-pengadaan/{$idLama}");

        expect(ProcurementRequest::query()->draf()->exists())->toBeFalse()
            ->and(ProcurementRequest::count())->toBe(1)
            ->and(ProcurementRequestDetail::sole()->amount_request)->not->toBe(99);
    });

    it('mengarahkan pengajuan ulang draf yang sudah diajukan ke usulannya', function () {
        seedDraf();
        $idLama = idDrafUsulan();
        $judul = ProcurementRequest::findOrFail($idLama)->judul();

        $this->actingAs(pengelolaGudang())->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => $idLama]);

        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => $idLama])
            ->assertRedirect("/usulan-pengadaan/{$idLama}")
            ->assertSessionHas('status', "{$judul} sudah diajukan sebelumnya.");

        expect(AuditLog::where('activity', 'like', 'Mengajukan usulan pengadaan%')->count())->toBe(1);
    });

    it('tidak mengajukan atau membuang draf baru dari halaman draf lama', function () {
        seedDraf();
        $idLama = idDrafUsulan();

        $this->actingAs(pengelolaGudang())->delete('/usulan-pengadaan/draf', ['draft_id' => $idLama]);
        ProcurementDraftScanner::perbaruiSeluruh();
        $idBaru = idDrafUsulan();

        expect($idBaru)->not->toBe($idLama)->not->toBe('');

        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => $idLama])
            ->assertRedirect('/usulan-pengadaan/draf')
            ->assertSessionHas('error');

        $this->actingAs(pengelolaGudang())
            ->delete('/usulan-pengadaan/draf', ['draft_id' => $idLama])
            ->assertRedirect('/usulan-pengadaan/draf');

        expect(ProcurementRequest::findOrFail($idBaru)->isDraf())->toBeTrue();
    });

    it('membawa id draf yang ditampilkan di ketiga formulir halaman draf', function () {
        seedDraf();

        $html = $this->actingAs(pengelolaGudang())->get('/usulan-pengadaan/draf')->getContent();

        expect(substr_count($html, 'name="draft_id" value="'.idDrafUsulan().'"'))->toBe(3);
    });
});

describe('menuntaskan draf', function () {
    it('mengajukan draf: memindahkan status, mengisi pengaju, mencatat audit, dan memberi tahu Pejabat Pengadaan', function () {
        $item = seedDraf();
        $petugas = pengelolaGudang();
        $pejabat = pejabatPengadaan();

        $draft = ProcurementRequest::query()->draf()->firstOrFail();

        $this->actingAs($petugas)
            ->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => idDrafUsulan()])
            ->assertRedirect("/usulan-pengadaan/{$draft->procurement_request_id}");

        $draft->refresh();

        expect($draft->status)->toBe(ProcurementRequestStatus::Diajukan)
            ->and($draft->user_id)->toBe($petugas->user_id);

        expect(AuditLog::where('user_id', $petugas->user_id)
            ->where('activity', 'like', 'Mengajukan usulan pengadaan%')
            ->exists())->toBeTrue();

        expect(Notification::query()
            ->where('user_id', $pejabat->user_id)
            ->where('type', NotificationType::UsulanMenungguPersetujuan)
            ->exists())->toBeTrue();
    });

    it('menolak mengajukan bila tidak ada draf', function () {
        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => idDrafUsulan()])
            ->assertSessionHas('error');

        expect(ProcurementRequest::count())->toBe(0);
    });

    it('menolak mengajukan draf yang kosong', function () {
        $draft = ProcurementRequest::create([
            'user_id' => null,
            'status' => ProcurementRequestStatus::Draf,
        ]);

        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => idDrafUsulan()])
            ->assertSessionHas('error');

        expect($draft->refresh()->status)->toBe(ProcurementRequestStatus::Draf);
    });

    it('membuang draf beserta rinciannya dan mencatat audit', function () {
        $item = seedDraf();
        $petugas = pengelolaGudang();
        $draftId = ProcurementRequest::query()->draf()->firstOrFail()->procurement_request_id;

        $this->actingAs($petugas)
            ->delete('/usulan-pengadaan/draf', ['draft_id' => idDrafUsulan()])
            ->assertRedirect('/usulan-pengadaan');

        expect(ProcurementRequest::whereKey($draftId)->doesntExist())->toBeTrue()
            ->and(AuditLog::where('user_id', $petugas->user_id)
                ->where('activity', 'like', 'Membuang draf usulan pengadaan%')
                ->exists())->toBeTrue();
    });

    it('menolak membuang bila tidak ada draf', function () {
        $this->actingAs(pengelolaGudang())
            ->delete('/usulan-pengadaan/draf', ['draft_id' => idDrafUsulan()])
            ->assertSessionHas('error');
    });
});

describe('notifikasi draf tersedia', function () {
    it('memberi tahu Pengelola Gudang dan Admin, bukan Pejabat Pengadaan', function () {
        $gudang = pengelolaGudang();
        $admin = User::factory()->role(UserRole::Admin)->create();
        $pejabat = pejabatPengadaan();

        seedDraf();

        expect(Notification::query()->where('user_id', $gudang->user_id)->where('type', NotificationType::DraftUsulanTersedia)->exists())->toBeTrue()
            ->and(Notification::query()->where('user_id', $admin->user_id)->where('type', NotificationType::DraftUsulanTersedia)->exists())->toBeTrue()
            ->and(Notification::query()->where('user_id', $pejabat->user_id)->where('type', NotificationType::DraftUsulanTersedia)->exists())->toBeFalse();
    });

    it('menutup diri saat draf dibuang', function () {
        $gudang = pengelolaGudang();
        $item = seedDraf();

        $this->actingAs($gudang)->delete('/usulan-pengadaan/draf', ['draft_id' => idDrafUsulan()]);

        expect(Notification::query()->where('user_id', $gudang->user_id)->where('type', NotificationType::DraftUsulanTersedia)->exists())->toBeFalse();
    });

    it('menutup diri saat draf diajukan', function () {
        $gudang = pengelolaGudang();
        $item = seedDraf();

        $this->actingAs($gudang)->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => idDrafUsulan()]);

        expect(Notification::query()->where('user_id', $gudang->user_id)->where('type', NotificationType::DraftUsulanTersedia)->exists())->toBeFalse();
    });
});

describe('daftar usulan menyembunyikan draf', function () {
    it('mengecualikan draf dari hitungan dan daftar usulan sungguhan', function () {
        buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]);
        seedDraf();

        expect(ProcurementRequest::query()->bukanDraf()->count())->toBe(1);

        $draft = ProcurementRequest::query()->draf()->firstOrFail();

        // Bukan assertDontSee($draft->judul()): UUID berurut dari HasUuids bisa
        // berbagi 8 karakter awal dengan usulan sungguhan yang dibuat pada
        // detik yang sama, membuat judul()-nya identik walau baris berbeda
        // (gotcha yang sudah dikenal proyek ini). Tautan berisi UUID penuh
        // tidak pernah bertabrakan seperti itu.
        $this->actingAs(pengelolaGudang())
            ->get('/usulan-pengadaan')
            ->assertOk()
            ->assertDontSee("/usulan-pengadaan/{$draft->procurement_request_id}", false)
            ->assertSee('Tinjau Draf');
    });

    it('mengalihkan akses langsung ke draf lewat rute tampilan usulan', function () {
        seedDraf();
        $draft = ProcurementRequest::query()->draf()->firstOrFail();

        $this->actingAs(pengelolaGudang())
            ->get("/usulan-pengadaan/{$draft->procurement_request_id}")
            ->assertRedirect('/usulan-pengadaan/draf');
    });
});

describe('barang dalam draf tidak dapat dihapus', function () {
    it('menolak menghapus barang yang masih menjadi baris draf terbuka', function () {
        $item = seedDraf();

        $this->actingAs(pengelolaGudang())
            ->delete("/barang/{$item->item_id}")
            ->assertSessionHas('error');

        expect(Item::whereKey($item->item_id)->exists())->toBeTrue();
    });
});

describe('barang yang sedang dalam proses pengadaan', function () {
    it('tidak memasukkan lagi barang dari draf yang sudah diajukan saat halaman draf dibuka', function () {
        $gudang = pengelolaGudang();
        $lama = barangKurangDiDraf();

        $this->actingAs($gudang)->post('/usulan-pengadaan/draf/ajukan', ['draft_id' => idDrafUsulan()]);

        // Barang lain yang kemudian kurang melahirkan draf baru, sama seperti
        // permohonan pengguna yang disetujui.
        $baru = barangKurangDiDraf();

        $this->actingAs($gudang)->get('/usulan-pengadaan/draf')->assertOk();

        expect(barisDrafUntuk($baru))->not->toBeNull()
            ->and(barisDrafUntuk($lama))->toBeNull();
    });

    it('membuang baris otomatis begitu barangnya diajukan lewat formulir manual', function () {
        $item = barangKurangDiDraf();

        buatUsulan(pengelolaGudang(), [['item_id' => $item->item_id, 'amount_request' => 10]]);

        expect(barisDrafUntuk($item))->toBeNull();

        ProcurementDraftScanner::perbaruiSeluruh();

        expect(barisDrafUntuk($item))->toBeNull();
    });

    it('tetap melewati barang selama usulannya disetujui dan belum direalisasi', function () {
        $item = barangKurangDiDraf();

        setujuiUsulan(pejabatPengadaan(), buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        ProcurementDraftScanner::perbaruiSeluruh();

        expect(barisDrafUntuk($item))->toBeNull();
    });

    it('mengembalikan barang yang tidak disetujui ke draf seketika', function () {
        $ditolak = barangKurangDiDraf();
        $disetujui = barangKurangDiDraf();

        $usulan = buatUsulan(pengelolaGudang(), [
            ['item_id' => $ditolak->item_id, 'amount_request' => 10],
            ['item_id' => $disetujui->item_id, 'amount_request' => 10],
        ]);

        // Prasyarat untuk keempat uji pelepasan ini: barangnya sudah keluar
        // dari draf, supaya yang terbukti memang pemicu pelepasannya.
        ProcurementDraftScanner::perbaruiSeluruh();
        expect(barisDrafUntuk($ditolak))->toBeNull();

        $this->actingAs(pejabatPengadaan())
            ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/setujui", [
                'granted' => $usulan->details->mapWithKeys(fn ($detail) => [
                    $detail->procurement_request_detail_id => $detail->item_id === $ditolak->item_id ? 0 : 10,
                ])->all(),
            ])
            ->assertSessionHasNoErrors();

        expect(barisDrafUntuk($ditolak)?->is_auto)->toBeTrue()
            ->and(barisDrafUntuk($disetujui))->toBeNull();
    });

    it('mengembalikan seluruh barang usulan yang ditolak ke draf seketika', function () {
        $item = barangKurangDiDraf();
        $usulan = buatUsulan(pengelolaGudang(), [['item_id' => $item->item_id, 'amount_request' => 10]]);

        ProcurementDraftScanner::perbaruiSeluruh();
        expect(barisDrafUntuk($item))->toBeNull();

        $this->actingAs(pejabatPengadaan())->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/tolak");

        expect(barisDrafUntuk($item)?->is_auto)->toBeTrue()
            ->and(barisDrafUntuk($item)->source)->toBe(ProcurementRequestSource::PemantauanStok);
    });

    it('tetap melewati barang selama realisasinya berjalan, termasuk yang ditolak Kasubbag', function () {
        $item = barangKurangDiDraf();
        $usulan = setujuiUsulan(pejabatPengadaan(), buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        $procurement = buatRealisasi(pejabatPengadaan(), $usulan, Supplier::factory()->create());

        ProcurementDraftScanner::perbaruiSeluruh();
        expect(barisDrafUntuk($item))->toBeNull();

        // Realisasi yang ditolak masih bisa diperbaiki lalu diajukan ulang
        // (ADR 0030), jadi barangnya belum boleh diusulkan dua kali.
        $this->actingAs(kasubbagUmumTi())
            ->patch("/pengadaan/{$procurement->procurement_id}/tolak", ['review_note' => 'Harga terlalu tinggi.']);

        expect($procurement->fresh()->status)->toBe(ProcurementStatus::Ditolak);

        ProcurementDraftScanner::perbaruiSeluruh();
        expect(barisDrafUntuk($item))->toBeNull();
    });

    it('mengembalikan barang ke draf seketika saat realisasinya dibatalkan', function () {
        $item = barangKurangDiDraf();
        $usulan = setujuiUsulan(pejabatPengadaan(), buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        $procurement = buatRealisasi(pejabatPengadaan(), $usulan, Supplier::factory()->create());

        ProcurementDraftScanner::perbaruiSeluruh();
        expect(barisDrafUntuk($item))->toBeNull();

        $this->actingAs(pejabatPengadaan())
            ->patch("/pengadaan/{$procurement->procurement_id}/batal", pembatalanPayload())
            ->assertSessionHasNoErrors();

        expect($procurement->fresh()->status)->toBe(ProcurementStatus::Dibatalkan)
            ->and(barisDrafUntuk($item)?->is_auto)->toBeTrue();
    });

    it('mengembalikan barang yang datang kurang ke draf setelah penerimaan', function () {
        $kurang = barangKurangDiDraf(stok: 0, min: 10, max: 50);
        $lengkap = barangKurangDiDraf(stok: 0, min: 10, max: 50);
        $gudang = pengelolaGudang();
        $pejabat = pejabatPengadaan();

        $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
            ['item_id' => $kurang->item_id, 'amount_request' => 50],
            ['item_id' => $lengkap->item_id, 'amount_request' => 50],
        ]));
        $procurement = buatRealisasiSiapDiterima($pejabat, $usulan, Supplier::factory()->create());

        ProcurementDraftScanner::perbaruiSeluruh();
        expect(barisDrafUntuk($kurang))->toBeNull();

        ProcurementReceiptPoster::post(
            procurement: $procurement,
            diterima: $procurement->details->mapWithKeys(fn ($detail) => [
                $detail->procurement_detail_id => $detail->item_id === $kurang->item_id ? 5 : 50,
            ])->all(),
            actor: $gudang,
            receivedAt: now(),
        );

        expect(barisDrafUntuk($kurang)?->amount_request)->toBe(45)
            ->and(barisDrafUntuk($lengkap))->toBeNull();
    });

    it('membuang baris otomatis barang yang dibeli lewat realisasi langsung', function () {
        $item = barangKurangDiDraf();

        buatRealisasiLangsung(pejabatPengadaan(), Supplier::factory()->create(), [barisLangsung($item)]);

        expect(barisDrafUntuk($item))->toBeNull();
    });

    it('mengembalikan barang tambahan yang dihapus dari realisasi ke draf', function () {
        $pejabat = pejabatPengadaan();
        $supplier = Supplier::factory()->create();
        $tambahan = barangKurangDiDraf();

        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        $items = realisasiPayload($usulan, $supplier)['items'];
        $items[] = ['item_id' => $tambahan->item_id, 'amount' => 5, 'unit_price' => 1000];
        $procurement = buatRealisasi($pejabat, $usulan, $supplier, ['items' => $items]);

        expect(barisDrafUntuk($tambahan))->toBeNull();

        $payload = ubahPayload($procurement->load('details'));
        $idTambahan = $procurement->details->firstWhere('item_id', $tambahan->item_id)->procurement_detail_id;
        $payload['items'] = array_values(array_filter(
            $payload['items'],
            fn (array $baris) => $baris['procurement_detail_id'] !== $idTambahan,
        ));

        $this->actingAs($pejabat)
            ->put("/pengadaan/{$procurement->procurement_id}", $payload)
            ->assertSessionHasNoErrors();

        expect(barisDrafUntuk($tambahan)?->is_auto)->toBeTrue();
    });

    it('tidak menyentuh baris manual atas barang yang sedang dalam proses', function () {
        $gudang = pengelolaGudang();
        $item = barangKurangDiDraf();
        $lain = barangKurangDiDraf();

        buatUsulan($gudang, [['item_id' => $item->item_id, 'amount_request' => 10]]);

        $this->actingAs($gudang)->put('/usulan-pengadaan/draf', [
            'draft_id' => idDrafUsulan(),
            'items' => [
                ['item_id' => $item->item_id, 'amount_request' => 3],
                ['item_id' => $lain->item_id, 'amount_request' => 40],
            ],
        ])->assertSessionHasNoErrors();

        ProcurementDraftScanner::perbaruiSeluruh();

        expect(barisDrafUntuk($item)?->amount_request)->toBe(3)
            ->and(barisDrafUntuk($item)->is_auto)->toBeFalse();
    });

    it('menampilkan barang yang dilewati beserta dokumennya di halaman draf', function () {
        $gudang = pengelolaGudang();
        $diusulkan = barangKurangDiDraf();
        $dibeli = barangKurangDiDraf();

        $usulan = buatUsulan($gudang, [['item_id' => $diusulkan->item_id, 'amount_request' => 10]]);
        $procurement = buatRealisasiLangsung(pejabatPengadaan(), Supplier::factory()->create(), [barisLangsung($dibeli)]);

        // Kedua barangnya sudah dalam proses, jadi drafnya ikut terhapus —
        // bagian ini justru paling dibutuhkan saat draf tidak ada.
        expect(ProcurementRequest::query()->draf()->exists())->toBeFalse();

        $this->actingAs($gudang)
            ->get('/usulan-pengadaan/draf')
            ->assertOk()
            ->assertSee('Sedang dalam Proses Pengadaan')
            ->assertSee($diusulkan->item_code)
            ->assertSee($dibeli->item_code)
            ->assertSee("/usulan-pengadaan/{$usulan->procurement_request_id}", false)
            ->assertSee("/pengadaan/{$procurement->procurement_id}", false);
    });

    it('tidak menampilkan bagiannya bila tidak ada barang yang dilewati', function () {
        seedDraf();

        $this->actingAs(pengelolaGudang())
            ->get('/usulan-pengadaan/draf')
            ->assertOk()
            ->assertDontSee('Sedang dalam Proses Pengadaan');
    });
});
