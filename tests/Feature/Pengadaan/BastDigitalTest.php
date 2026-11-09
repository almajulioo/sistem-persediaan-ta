<?php

use App\Enums\AlasanPengadaan;
use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\MutationType;
use App\Enums\NotificationType;
use App\Enums\PihakBast;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Procurement;
use App\Models\ProcurementReceiptSignature;
use App\Models\StockMutation;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BastPengadaan;
use App\Support\NotificationScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * BAST pengadaan digital dengan TTE QR (ADR 0051): hasil pemeriksaan → nomor
 * → TTE Pihak Pertama dan Pihak Kedua, stok masuk pada TTE kedua.
 *
 * Fixture-nya mandiri — realisasi dibuat langsung lewat model dalam status
 * yang dibutuhkan — supaya berkas ini bisa dijalankan sendiri. Nama pembantu
 * bersufiks `Tte` karena fungsi Pest berbagi ruang nama global.
 */
function penggunaTte(UserRole $role, array $atribut = []): User
{
    return User::factory()->role($role)->create($atribut);
}

/**
 * Realisasi langsung yang sudah disetujui dan dibelanjakan, satu barang per
 * angka di `$dipesan`, harga Rp12.500. Stok awal barangnya nol.
 *
 * @param  list<int>  $dipesan
 */
function realisasiTte(User $pejabat, array $dipesan = [10], ProcurementStatus $status = ProcurementStatus::MenungguPenerimaan): Procurement
{
    $procurement = Procurement::create([
        'supplier_id' => Supplier::factory()->create(['supplier_name' => 'CV Tanjung Karang'])->supplier_id,
        'procurement_date' => now()->subDays(3)->toDateString(),
        'basis_reason' => AlasanPengadaan::BelanjaRutinNilaiKecil,
        'basis_note' => 'Kebutuhan rutin bulanan.',
        'status' => $status,
        'user_id' => $pejabat->user_id,
    ]);

    $procurement->forceFill([
        'reviewed_at' => now()->subDays(2),
        'purchased_at' => $status === ProcurementStatus::ProsesBelanja ? null : now()->subDay(),
        'purchased_by' => $status === ProcurementStatus::ProsesBelanja ? null : $pejabat->user_id,
    ])->save();

    foreach ($dipesan as $jumlah) {
        $procurement->details()->create([
            'item_id' => Item::factory()->create(['stock_current' => 0, 'stock_min' => 0, 'stock_max' => 0])->item_id,
            'amount' => $jumlah,
            'unit_price' => 12500,
        ]);
    }

    return $procurement->fresh('details');
}

/**
 * @param  array<string, int>|null  $jumlah  procurement_detail_id => diterima; null = sesuai pesanan.
 */
function periksaBarangTte(Procurement $procurement, User $gudang, ?array $jumlah = null, ?string $tanggal = null, ?string $catatan = null): TestResponse
{
    return test()->actingAs($gudang)->post("/pengadaan/{$procurement->procurement_id}/penerimaan", [
        'received_at' => $tanggal ?? now()->toDateString(),
        'received' => $jumlah ?? $procurement->details
            ->mapWithKeys(fn ($detail) => [$detail->procurement_detail_id => $detail->amount])
            ->all(),
        'notes' => $catatan,
    ]);
}

function nomoriBastTte(Procurement $procurement, User $pengguna, string $nomor = '010/UP_TUP/445237/2026'): TestResponse
{
    return test()->actingAs($pengguna)->patch("/pengadaan/{$procurement->procurement_id}/bast", ['bast_number' => $nomor]);
}

function bubuhkanTte(Procurement $procurement, User $pengguna, array $isian = []): TestResponse
{
    return test()->actingAs($pengguna)->post("/pengadaan/{$procurement->procurement_id}/bast/tte", $isian);
}

/**
 * Realisasi yang sudah diperiksa dan bernomor, siap ditandatangani.
 */
function siapDitandatanganiTte(Procurement $procurement, User $gudang, ?array $jumlah = null): Procurement
{
    periksaBarangTte($procurement, $gudang, $jumlah);
    nomoriBastTte($procurement, $gudang);

    return $procurement->fresh(['details', 'receipt.signatures']);
}

function ttdTte(Procurement $procurement, PihakBast $pihak): ?ProcurementReceiptSignature
{
    return $procurement->fresh('receipt.signatures')->receipt?->tteAktif($pihak);
}

function htmlBastTte(Procurement $procurement, bool $denganTte = true): string
{
    return view('pdf.bast-pengadaan', [
        'bast' => BastPengadaan::untukCetak($procurement->fresh(), $denganTte),
    ])->render();
}

beforeEach(function () {
    $this->pejabat = penggunaTte(UserRole::PejabatPengadaan, [
        'fullname' => 'Ridwan Pelaksana',
        'nip' => '198506142003121001',
        'position' => 'Pengelola Pengadaan Barang/Jasa Ahli Pertama',
    ]);
    $this->gudang = penggunaTte(UserRole::PengelolaGudang, [
        'fullname' => 'Yakub Persediaan',
        'nip' => '197409142023211005',
        'position' => 'Arsiparis Ahli Pertama',
    ]);
    $this->admin = penggunaTte(UserRole::Admin);
    $this->kasubbag = penggunaTte(UserRole::KasubbagUmumTi);

    $this->procurement = realisasiTte($this->pejabat, [10, 4]);
    $this->url = "/pengadaan/{$this->procurement->procurement_id}";
});

describe('hak akses BAST digital', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        foreach (['/bast', '/bast/kosong', '/penerimaan/create'] as $ruas) {
            $this->get($this->url.$ruas)->assertRedirect('/login');
        }

        $this->post("{$this->url}/bast/tte")->assertRedirect('/login');
    });

    it('menolak peran di luar pengadaan', function () {
        $kepala = penggunaTte(UserRole::KepalaUnit);

        $this->actingAs($kepala)->get("{$this->url}/bast")->assertForbidden();
        $this->actingAs($kepala)->post("{$this->url}/bast/tte")->assertForbidden();
    });

    it('membuka BAST dan BAST kosong bagi kedua pihak, Kasubbag, dan Admin', function () {
        foreach ([$this->pejabat, $this->gudang, $this->kasubbag, $this->admin] as $pengguna) {
            foreach (['/bast', '/bast/kosong'] as $ruas) {
                $response = $this->actingAs($pengguna)->get($this->url.$ruas);

                $response->assertOk();
                expect($response->getContent())->toStartWith('%PDF');
            }
        }
    });

    it('menolak Admin membubuhkan TTE, walau BAST-nya siap', function () {
        // ADR 0051: satu-satunya pengecualian Gate::before. Admin tetap
        // membuka halamannya.
        siapDitandatanganiTte($this->procurement, $this->gudang);

        bubuhkanTte($this->procurement, $this->admin)->assertForbidden();
        $this->actingAs($this->admin)->get($this->url)->assertOk()->assertDontSee('Ya, tandatangani');

        expect(ProcurementReceiptSignature::count())->toBe(0);
    });

    it('menolak Kasubbag memeriksa, menomori, maupun menandatangani', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);

        periksaBarangTte($this->procurement, $this->kasubbag)->assertForbidden();
        nomoriBastTte($this->procurement, $this->kasubbag, '099/X/2026')->assertForbidden();
        bubuhkanTte($this->procurement, $this->kasubbag)->assertForbidden();
    });

    it('membuka formulir hasil pemeriksaan hanya bagi Pengelola Gudang', function () {
        $this->actingAs($this->pejabat)->get("{$this->url}/penerimaan/create")->assertForbidden();
        $this->actingAs($this->gudang)->get("{$this->url}/penerimaan/create")
            ->assertOk()
            ->assertSee('Hasil Pemeriksaan Barang');
    });
});

describe('kartu BAST di halaman detail', function () {
    it('menunjukkan langkah pertama dan tombol unduh kepada gudang', function () {
        $this->actingAs($this->gudang)->get($this->url)
            ->assertOk()
            ->assertSee('Berita Acara Serah Terima')
            ->assertSee('Catat Hasil Pemeriksaan')
            ->assertSee('Lihat BAST')
            ->assertSee('Unduh BAST Kosong');
    });

    it('menunjukkan siapa yang ditunggu kepada peran yang tidak memegang tombolnya', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);

        $this->actingAs($this->kasubbag)->get($this->url)
            ->assertOk()
            ->assertSee('Menunggu Pejabat Pengadaan')
            ->assertSee('Menunggu Pengelola Gudang')
            ->assertDontSee('Ya, tandatangani');
    });

    it('menampilkan dialog TTE hanya kepada pihak yang belum menandatangani', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);

        $this->actingAs($this->pejabat)->get($this->url)->assertSee('Tandatangani BAST sebagai Pihak Pertama?');
        bubuhkanTte($this->procurement, $this->pejabat);

        $this->actingAs($this->pejabat)->get($this->url)->assertDontSee('Ya, tandatangani');
        $this->actingAs($this->gudang)->get($this->url)
            ->assertSee('Tandatangani BAST sebagai Pihak Kedua?')
            ->assertSee('Setelah ditandatangani, barang masuk ke stok');
    });
});

describe('hasil pemeriksaan', function () {
    it('menyimpan draf penerimaan tanpa mengubah stok', function () {
        $detail = $this->procurement->details->first();

        periksaBarangTte($this->procurement, $this->gudang, [
            $detail->procurement_detail_id => 7,
            $this->procurement->details->last()->procurement_detail_id => 4,
        ])->assertRedirect($this->url)->assertSessionHas('status');

        $procurement = $this->procurement->fresh(['details', 'receipt']);

        expect($procurement->isMenungguPenerimaan())->toBeTrue()
            ->and($procurement->receipt->received_by)->toBe($this->gudang->user_id)
            ->and($detail->fresh()->amount_checked)->toBe(7)
            ->and($detail->fresh()->amount_received)->toBeNull()
            ->and($detail->item->fresh()->stock_current)->toBe(0)
            ->and(StockMutation::count())->toBe(0)
            ->and(AuditLog::where('module', AuditModule::Penerimaan)->where('event', AuditEvent::Tambah)->count())->toBe(1);
    });

    it('dapat diubah, dan kiriman yang sama tidak mencatat apa pun', function () {
        periksaBarangTte($this->procurement, $this->gudang);
        periksaBarangTte($this->procurement, $this->gudang);

        $detail = $this->procurement->details->first();
        periksaBarangTte($this->procurement, $this->gudang, [
            $detail->procurement_detail_id => 3,
            $this->procurement->details->last()->procurement_detail_id => 4,
        ]);

        expect($detail->fresh()->amount_checked)->toBe(3)
            ->and(AuditLog::where('module', AuditModule::Penerimaan)->where('event', AuditEvent::Ubah)->count())->toBe(1);
    });

    it('ditolak selama barang belum dibelanjakan', function () {
        $belanja = realisasiTte($this->pejabat, [5], ProcurementStatus::ProsesBelanja);

        periksaBarangTte($belanja, $this->gudang)
            ->assertRedirect("/pengadaan/{$belanja->procurement_id}")
            ->assertSessionHas('error');

        expect($belanja->fresh()->receipt)->toBeNull();
    });
});

describe('nomor BAST', function () {
    it('baru dapat diisi setelah hasil pemeriksaan dicatat', function () {
        nomoriBastTte($this->procurement, $this->gudang)
            ->assertRedirect($this->url)
            ->assertSessionHas('error', 'Catat hasil pemeriksaan barang terlebih dahulu.');
    });

    it('diisi salah satu pihak lalu kembali ke halaman detail, bukan ke PDF', function () {
        periksaBarangTte($this->procurement, $this->gudang);

        nomoriBastTte($this->procurement, $this->pejabat, '021/UP_TUP/445237/2026')
            ->assertRedirect($this->url)
            ->assertSessionHas('status');

        expect($this->procurement->fresh()->receipt->bast_number)->toBe('021/UP_TUP/445237/2026');
    });
});

describe('membubuhkan TTE', function () {
    it('ditolak sebelum hasil pemeriksaan dan sebelum nomor diisi', function () {
        bubuhkanTte($this->procurement, $this->pejabat)->assertSessionHas('error');

        periksaBarangTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat)->assertSessionHas('error', 'Isi nomor BAST terlebih dahulu.');

        expect(ProcurementReceiptSignature::count())->toBe(0);
    });

    it('menentukan pihak dari peran, bukan dari isian formulir', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);

        bubuhkanTte($this->procurement, $this->pejabat, ['party' => 'kedua'])->assertRedirect($this->url);

        $ttd = ProcurementReceiptSignature::sole();

        expect($ttd->party)->toBe(PihakBast::Pertama)
            ->and($ttd->user_id)->toBe($this->pejabat->user_id)
            ->and($ttd->signer_name)->toBe('Ridwan Pelaksana')
            ->and($ttd->signer_nip)->toBe('198506142003121001')
            ->and(strlen($ttd->verification_code))->toBe(40);
    });

    it('menolak TTE kedua kalinya dari pihak yang sama', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);

        bubuhkanTte($this->procurement, $this->pejabat);
        bubuhkanTte($this->procurement, penggunaTte(UserRole::PejabatPengadaan))
            ->assertSessionHas('error', 'BAST ini sudah ditandatangani sebagai Pihak Pertama.');

        expect(ProcurementReceiptSignature::count())->toBe(1);
    });

    it('membekukan isi BAST pada TTE pertama tanpa memasukkan stok', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);

        bubuhkanTte($this->procurement, $this->gudang)->assertSessionHas('status', 'TTE sebagai Pihak Kedua dibubuhkan.');

        $receipt = $this->procurement->fresh()->receipt;

        expect($receipt->isiUtuh())->toBeTrue()
            ->and($receipt->content['nomor'])->toBe('010/UP_TUP/445237/2026')
            ->and(ProcurementReceiptSignature::sole()->content_hash)->toBe($receipt->content_hash)
            ->and($this->procurement->fresh()->isMenungguPenerimaan())->toBeTrue()
            ->and(StockMutation::count())->toBe(0)
            ->and(AuditLog::where('event', AuditEvent::TandaTangan)->count())->toBe(1);
    });

    it('memasukkan stok sebesar hasil pemeriksaan pada TTE kedua, dicatat Pihak Kedua', function () {
        $kertas = $this->procurement->details->first();
        $tinta = $this->procurement->details->last();

        // Dipesan 10 dan 4; datang 7 dan 0.
        siapDitandatanganiTte($this->procurement, $this->gudang, [
            $kertas->procurement_detail_id => 7,
            $tinta->procurement_detail_id => 0,
        ]);

        // Urutan bebas: gudang lebih dulu, Pejabat Pengadaan menutup.
        bubuhkanTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat)
            ->assertRedirect($this->url)
            ->assertSessionHas('status', 'BAST lengkap ditandatangani. Stok sudah bertambah.');

        $mutasi = StockMutation::sole();

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::Diterima)
            ->and($kertas->item->fresh()->stock_current)->toBe(7)
            ->and($tinta->item->fresh()->stock_current)->toBe(0)
            ->and($kertas->fresh()->amount_received)->toBe(7)
            ->and($tinta->fresh()->amount_received)->toBe(0)
            ->and($mutasi->mutation_type)->toBe(MutationType::Masuk)
            ->and($mutasi->reference_id)->toBe($this->procurement->procurement_id)
            ->and($mutasi->reference_number)->toBe('010/UP_TUP/445237/2026')
            // Penerimaan barang tetap tugas gudang (ADR 0008), siapa pun yang
            // menandatangani terakhir.
            ->and($mutasi->user_id)->toBe($this->gudang->user_id)
            ->and(AuditLog::where('event', AuditEvent::Terima)->where('subject_id', $this->procurement->procurement_id)->count())->toBe(1);
    });

    it('memberi tahu Pejabat Pengadaan pembuatnya bila gudang yang menutup', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);

        bubuhkanTte($this->procurement, $this->pejabat);
        bubuhkanTte($this->procurement, $this->gudang);

        expect(Notification::where('user_id', $this->pejabat->user_id)
            ->where('type', NotificationType::PenerimaanDikonfirmasi)
            ->exists())->toBeTrue();
    });

    it('menutup notifikasi stok habis begitu stoknya masuk', function () {
        $item = $this->procurement->details->first()->item;
        $item->forceFill(['stock_min' => 5])->save();
        NotificationScanner::periksaBarang($item->fresh());

        $habis = fn () => Notification::where('type', NotificationType::StokHabis)
            ->where('subject_id', $item->item_id)
            ->exists();
        expect($habis())->toBeTrue();

        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);
        bubuhkanTte($this->procurement, $this->gudang);

        expect($habis())->toBeFalse();
    });

    it('memasukkan jumlah yang ditandatangani, bukan kolom yang diubah di luar aplikasi', function () {
        $detail = $this->procurement->details->first();
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);

        DB::table('procurement_details')
            ->where('procurement_detail_id', $detail->procurement_detail_id)
            ->update(['amount_checked' => 999]);

        bubuhkanTte($this->procurement, $this->gudang);

        expect($detail->item->fresh()->stock_current)->toBe(10)
            ->and($detail->fresh()->amount_received)->toBe(10);
    });

    it('menolak menandatangani isi yang tidak cocok lagi dengan sidiknya', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);

        $isi = $this->procurement->fresh()->receipt->content;
        $isi['rincian'][0]['diterima'] = 999;
        DB::table('procurement_receipts')->update(['content' => json_encode($isi)]);

        bubuhkanTte($this->procurement, $this->gudang)
            ->assertSessionHas('error', 'Isi BAST tidak cocok dengan sidik dokumennya. Hubungi Admin.');

        expect($this->procurement->fresh()->isMenungguPenerimaan())->toBeTrue()
            ->and(StockMutation::count())->toBe(0);
    });
});

describe('pencabutan TTE', function () {
    it('mencabut TTE bila hasil pemeriksaan diubah, dan QR lamanya tidak berlaku', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);
        $lama = ttdTte($this->procurement, PihakBast::Pertama);

        periksaBarangTte($this->procurement, $this->gudang, [
            $this->procurement->details->first()->procurement_detail_id => 8,
            $this->procurement->details->last()->procurement_detail_id => 4,
        ]);

        expect($lama->fresh()->dicabut())->toBeTrue()
            ->and($lama->fresh()->revoke_reason)->toBe(BastPengadaan::ALASAN_PEMERIKSAAN)
            ->and($this->procurement->fresh()->receipt->sudahDibekukan())->toBeFalse()
            ->and(AuditLog::where('activity', 'like', '%TTE Ridwan Pelaksana dicabut')->exists())->toBeTrue();

        auth()->logout();
        $this->get("/verifikasi/bast/{$lama->verification_code}")
            ->assertOk()
            ->assertSee('Tanda tangan tidak berlaku')
            ->assertSee(BastPengadaan::ALASAN_PEMERIKSAAN);
    });

    it('mencabut TTE bila nomor BAST diubah', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->gudang);

        nomoriBastTte($this->procurement, $this->pejabat, '011/UP_TUP/445237/2026');

        expect(ttdTte($this->procurement, PihakBast::Kedua))->toBeNull()
            ->and(ProcurementReceiptSignature::sole()->revoke_reason)->toBe(BastPengadaan::ALASAN_NOMOR);
    });

    it('tidak mencabut apa pun bila hasil pemeriksaan dikirim ulang tanpa perubahan', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);

        periksaBarangTte($this->procurement, $this->gudang);

        expect(ttdTte($this->procurement, PihakBast::Pertama))->not->toBeNull();
    });

    it('mencabut TTE bila realisasinya dibatalkan, dengan draf penerimaan tetap tersimpan', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);

        $this->actingAs($this->pejabat)->patch("{$this->url}/batal", [
            'cancel_reason' => \App\Enums\AlasanPembatalan::cases()[0]->value,
            'cancel_note' => 'Penyedia menarik barangnya.',
        ])->assertSessionHas('status');

        expect($this->procurement->fresh()->isDibatalkan())->toBeTrue()
            ->and($this->procurement->fresh()->receipt)->not->toBeNull()
            ->and(ProcurementReceiptSignature::sole()->revoke_reason)->toBe(BastPengadaan::ALASAN_REALISASI_DIBATALKAN);
    });

    it('mencabut TTE bila realisasinya diubah, dengan hasil pemeriksaan dipertahankan', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);

        $this->actingAs($this->pejabat)->put($this->url, [
            'supplier_id' => $this->procurement->supplier_id,
            'procurement_date' => $this->procurement->procurement_date->toDateString(),
            'basis_reason' => AlasanPengadaan::BelanjaRutinNilaiKecil->value,
            'basis_note' => 'Harga satuan dikoreksi.',
            'items' => $this->procurement->details->map(fn ($detail) => [
                'procurement_detail_id' => $detail->procurement_detail_id,
                'item_id' => $detail->item_id,
                'amount' => $detail->amount,
                'unit_price' => 13000,
            ])->all(),
        ])->assertSessionHasNoErrors();

        $procurement = $this->procurement->fresh(['details', 'receipt']);

        expect($procurement->isMenungguPersetujuan())->toBeTrue()
            ->and(ProcurementReceiptSignature::sole()->revoke_reason)->toBe(BastPengadaan::ALASAN_REALISASI_DIUBAH)
            ->and($procurement->details->every(fn ($detail) => $detail->amount_checked !== null))->toBeTrue();
    });

    it('mengunci pemeriksaan, nomor, dan TTE setelah kedua pihak bertanda tangan', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);
        bubuhkanTte($this->procurement, $this->gudang);

        periksaBarangTte($this->procurement, $this->gudang)->assertSessionHas('error');
        nomoriBastTte($this->procurement, $this->gudang, '012/UP_TUP/445237/2026')
            ->assertSessionHas('error', 'Nomor BAST terkunci karena BAST sudah ditandatangani kedua pihak.');
        bubuhkanTte($this->procurement, $this->gudang)->assertSessionHas('error');

        expect($this->procurement->fresh()->receipt->bast_number)->toBe('010/UP_TUP/445237/2026')
            ->and(ProcurementReceiptSignature::whereNull('revoked_at')->count())->toBe(2)
            ->and(StockMutation::count())->toBe(2);
    });
});

describe('verifikasi QR', function () {
    it('menampilkan isi lengkap kepada siapa pun tanpa login', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);
        $kode = ttdTte($this->procurement, PihakBast::Pertama)->verification_code;
        $nama = $this->procurement->details->first()->item->item_name;

        auth()->logout();
        $this->get("/verifikasi/bast/{$kode}")
            ->assertOk()
            ->assertSee('Tanda tangan elektronik sah')
            ->assertSee('Ridwan Pelaksana')
            ->assertSee('Pihak Pertama — Pelaksana Belanja')
            ->assertSee('010/UP_TUP/445237/2026')
            ->assertSee('CV Tanjung Karang')
            ->assertSee($nama)
            ->assertSee('175.000')
            ->assertSee('Menunggu tanda tangan pihak lain');

        $this->actingAs($this->gudang);
        bubuhkanTte($this->procurement, $this->gudang);
        auth()->logout();

        $this->get("/verifikasi/bast/{$kode}")->assertSee('Lengkap, ditandatangani kedua pihak');
    });

    it('menjawab 404 dengan halaman yang jelas untuk kode yang tidak dikenal', function () {
        $this->get('/verifikasi/bast/'.str_repeat('a', 40))
            ->assertNotFound()
            ->assertSee('Kode verifikasi tidak dikenal');
        $this->get('/verifikasi/bast/salah-ketik')->assertNotFound();
    });

    it('menyatakan tidak berlaku tanpa menampilkan isinya bila isi diutak-atik di basis data', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);
        $kode = ttdTte($this->procurement, PihakBast::Pertama)->verification_code;
        $nama = $this->procurement->details->first()->item->item_name;

        $isi = $this->procurement->fresh()->receipt->content;
        $isi['rincian'][0]['diterima'] = 999;
        DB::table('procurement_receipts')->update(['content' => json_encode($isi)]);

        $this->get("/verifikasi/bast/{$kode}")
            ->assertOk()
            ->assertSee('Tanda tangan tidak berlaku')
            ->assertSee('Isi dokumen tidak cocok')
            ->assertDontSee($nama);
    });

    it('memakai identitas saat TTE walau akunnya diubah kemudian', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);
        bubuhkanTte($this->procurement, $this->gudang);

        $this->pejabat->forceFill(['fullname' => 'Nama Baru Sekali', 'position' => 'Jabatan Baru'])->save();
        $kode = ttdTte($this->procurement, PihakBast::Pertama)->verification_code;

        $this->get("/verifikasi/bast/{$kode}")->assertSee('Ridwan Pelaksana')->assertDontSee('Nama Baru Sekali');

        expect(htmlBastTte($this->procurement))->toContain('Ridwan Pelaksana')
            ->not->toContain('Nama Baru Sekali');
    });
});

describe('isi PDF BAST', function () {
    it('memuat QR dan catatan TTE, sedangkan versi kosong tidak', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);

        expect(htmlBastTte($this->procurement))
            ->toContain('data:image/png;base64')
            ->toContain('Ditandatangani secara elektronik')
            ->toContain('Keaslian tanda tangan dapat diperiksa');

        expect(htmlBastTte($this->procurement, denganTte: false))
            ->not->toContain('data:image/png;base64')
            ->not->toContain('Keaslian tanda tangan dapat diperiksa')
            ->toContain('Ridwan Pelaksana')
            ->toContain('010/UP_TUP/445237/2026');
    });

    it('mencetak titik-titik dan seluruh barang sebelum pemeriksaan', function () {
        $html = htmlBastTte($this->procurement);
        $kode = $this->procurement->details->map(fn ($detail) => $detail->item->item_code);

        expect($html)->toContain('Nomor : ..............................................')
            ->toContain('.......... tanggal .......... bulan')
            ->toContain($kode[0])
            ->toContain($kode[1])
            ->not->toContain('data:image/png;base64');
    });

    it('menanam alamat verifikasi dari APP_URL, bukan dari host permintaan', function () {
        config(['app.url' => 'http://192.168.1.10:8000']);
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);
        $ttd = ttdTte($this->procurement, PihakBast::Pertama);

        expect(BastPengadaan::urlVerifikasi($ttd))
            ->toBe("http://192.168.1.10:8000/verifikasi/bast/{$ttd->verification_code}");
    });

    it('belum tersedia selama barang belum dibelanjakan', function () {
        $belanja = realisasiTte($this->pejabat, [5], ProcurementStatus::ProsesBelanja);

        $this->actingAs($this->gudang)->get("/pengadaan/{$belanja->procurement_id}/bast")
            ->assertRedirect("/pengadaan/{$belanja->procurement_id}")
            ->assertSessionHas('error');
    });
});

describe('notifikasi TTE', function () {
    it('memindahkan tagihan gudang ke TTE kedua pihak setelah pemeriksaan, tanpa Admin', function () {
        NotificationScanner::periksaRealisasi($this->procurement->fresh(['supplier', 'details', 'receipt']));
        $ada = fn (User $user, NotificationType $type) => Notification::where('user_id', $user->user_id)
            ->where('type', $type)
            ->exists();

        expect($ada($this->gudang, NotificationType::RealisasiMenungguPenerimaan))->toBeTrue();

        periksaBarangTte($this->procurement, $this->gudang);

        expect($ada($this->gudang, NotificationType::RealisasiMenungguPenerimaan))->toBeFalse()
            ->and($ada($this->gudang, NotificationType::BastMenungguTandaTangan))->toBeTrue()
            ->and($ada($this->pejabat, NotificationType::BastMenungguTandaTangan))->toBeTrue()
            ->and($ada($this->admin, NotificationType::BastMenungguTandaTangan))->toBeFalse();

        nomoriBastTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);

        expect($ada($this->pejabat, NotificationType::BastMenungguTandaTangan))->toBeFalse()
            ->and($ada($this->gudang, NotificationType::BastMenungguTandaTangan))->toBeTrue();

        bubuhkanTte($this->procurement, $this->gudang);

        expect(Notification::where('type', NotificationType::BastMenungguTandaTangan)->exists())->toBeFalse();
    });

    it('tidak pernah menagih pindaian untuk BAST digital', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        bubuhkanTte($this->procurement, $this->pejabat);
        bubuhkanTte($this->procurement, $this->gudang);

        $this->travel(10)->days();
        NotificationScanner::periksaSeluruhRealisasi();

        expect(Notification::where('type', NotificationType::BastBelumDiunggah)->exists())->toBeFalse();
    });
});

describe('antrean dasbor dan penyaring daftar', function () {
    it('menghitung BAST yang menunggu TTE pemakainya, sama dengan isi daftar yang dituju', function () {
        siapDitandatanganiTte($this->procurement, $this->gudang);
        $sudah = realisasiTte($this->pejabat, [3]);
        siapDitandatanganiTte($sudah, $this->gudang);
        nomoriBastTte($sudah, $this->gudang, '013/UP_TUP/445237/2026');
        bubuhkanTte($sudah, $this->pejabat);

        expect(Procurement::query()->menungguTte(PihakBast::Pertama)->count())->toBe(1)
            ->and(Procurement::query()->menungguTte(PihakBast::Kedua)->count())->toBe(2);

        $this->actingAs($this->pejabat)->get('/dasbor')
            ->assertOk()
            ->assertSee('BAST menunggu TTE Anda')
            ->assertSee('tte=pihak_pertama', false);

        $this->actingAs($this->pejabat)->get('/pengadaan?tte=pihak_pertama')
            ->assertOk()
            ->assertSee($this->procurement->procurement_id)
            ->assertDontSee($sudah->procurement_id);

        // Admin dan Kasubbag tidak menandatangani, jadi "Anda" tidak berlaku.
        $this->actingAs($this->admin)->get('/dasbor')->assertOk()->assertDontSee('BAST menunggu TTE Anda');
    });
});

it('menahan akun yang pernah bertanda tangan dari penghapusan', function () {
    siapDitandatanganiTte($this->procurement, $this->gudang);
    bubuhkanTte($this->procurement, $this->pejabat);

    expect($this->pejabat->blockingRelations())->toContain('tanda tangan elektronik BAST pengadaan');
});
