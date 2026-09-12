<?php

use App\Enums\AlasanSelisih;
use App\Enums\MutationType;
use App\Enums\NotificationType;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\ProcurementRequest;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\OpnamePoster;
use App\Support\ProcurementDraftScanner;
use Illuminate\Support\Facades\Artisan;

function mutasiManual(User $actor, Item $item, MutationType $type, int $amount): void
{
    test()->actingAs($actor)->post('/mutasi', [
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
function seedDraf(): Item
{
    $item = Item::factory()->habis()->create();
    ProcurementDraftScanner::perbaruiSeluruh();

    return $item;
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
            ->and($draft->details->first()->is_auto)->toBeTrue();
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

        $this->actingAs(pengelolaGudang())->delete('/usulan-pengadaan/draf');

        ProcurementDraftScanner::perbaruiSeluruh();

        $draftBaru = ProcurementRequest::query()->draf()->firstOrFail();
        expect($draftBaru->procurement_request_id)->not->toBe($draftLamaId);
    });

    it('membuat draf baru setelah draf dikosongkan lewat update, bukan hanya dibuang', function () {
        $item = seedDraf();

        $this->actingAs(pengelolaGudang())->put('/usulan-pengadaan/draf', ['items' => []]);

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

describe('menuntaskan draf', function () {
    it('mengajukan draf: memindahkan status, mengisi pengaju, mencatat audit, dan memberi tahu Pejabat Pengadaan', function () {
        $item = seedDraf();
        $petugas = pengelolaGudang();
        $pejabat = pejabatPengadaan();

        $draft = ProcurementRequest::query()->draf()->firstOrFail();

        $this->actingAs($petugas)
            ->post('/usulan-pengadaan/draf/ajukan')
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
            ->aktif()
            ->exists())->toBeTrue();
    });

    it('menolak mengajukan bila tidak ada draf', function () {
        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan/draf/ajukan')
            ->assertSessionHas('error');

        expect(ProcurementRequest::count())->toBe(0);
    });

    it('menolak mengajukan draf yang kosong', function () {
        $draft = ProcurementRequest::create([
            'user_id' => null,
            'source' => ProcurementRequestSource::PemantauanStok,
            'status' => ProcurementRequestStatus::Draf,
        ]);

        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan/draf/ajukan')
            ->assertSessionHas('error');

        expect($draft->refresh()->status)->toBe(ProcurementRequestStatus::Draf);
    });

    it('membuang draf beserta rinciannya dan mencatat audit', function () {
        $item = seedDraf();
        $petugas = pengelolaGudang();
        $draftId = ProcurementRequest::query()->draf()->firstOrFail()->procurement_request_id;

        $this->actingAs($petugas)
            ->delete('/usulan-pengadaan/draf')
            ->assertRedirect('/usulan-pengadaan');

        expect(ProcurementRequest::whereKey($draftId)->doesntExist())->toBeTrue()
            ->and(AuditLog::where('user_id', $petugas->user_id)
                ->where('activity', 'like', 'Membuang draf usulan pengadaan%')
                ->exists())->toBeTrue();
    });

    it('menolak membuang bila tidak ada draf', function () {
        $this->actingAs(pengelolaGudang())
            ->delete('/usulan-pengadaan/draf')
            ->assertSessionHas('error');
    });
});

describe('notifikasi draf tersedia', function () {
    it('memberi tahu Pengelola Gudang dan Admin, bukan Pejabat Pengadaan', function () {
        $gudang = pengelolaGudang();
        $admin = User::factory()->role(UserRole::Admin)->create();
        $pejabat = pejabatPengadaan();

        seedDraf();

        expect(Notification::query()->where('user_id', $gudang->user_id)->where('type', NotificationType::DraftUsulanTersedia)->aktif()->exists())->toBeTrue()
            ->and(Notification::query()->where('user_id', $admin->user_id)->where('type', NotificationType::DraftUsulanTersedia)->aktif()->exists())->toBeTrue()
            ->and(Notification::query()->where('user_id', $pejabat->user_id)->where('type', NotificationType::DraftUsulanTersedia)->exists())->toBeFalse();
    });

    it('menutup diri saat draf dibuang', function () {
        $gudang = pengelolaGudang();
        $item = seedDraf();

        $this->actingAs($gudang)->delete('/usulan-pengadaan/draf');

        expect(Notification::query()->where('user_id', $gudang->user_id)->where('type', NotificationType::DraftUsulanTersedia)->aktif()->exists())->toBeFalse();
    });

    it('menutup diri saat draf diajukan', function () {
        $gudang = pengelolaGudang();
        $item = seedDraf();

        $this->actingAs($gudang)->post('/usulan-pengadaan/draf/ajukan');

        expect(Notification::query()->where('user_id', $gudang->user_id)->where('type', NotificationType::DraftUsulanTersedia)->aktif()->exists())->toBeFalse();
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
