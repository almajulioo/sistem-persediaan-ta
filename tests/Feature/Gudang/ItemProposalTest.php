<?php

use App\Enums\ItemProposalStatus;
use App\Enums\NotificationType;
use App\Enums\SatuanBarang;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemApplication;
use App\Models\ItemProposal;
use App\Models\ItemSubcategory;
use App\Models\Notification;
use App\Models\StockMutation;
use App\Models\User;

/**
 * Usulan barang baru — pintu depan katalog (ADR 0032), yang sejak ADR 0053
 * hanya lahir dari permohonan barang baru dan diputuskan dari rincian
 * permohonan itu.
 *
 * Helper di berkas Pest bersifat global lintas berkas, jadi namanya dibedakan
 * dari helper modul lain.
 */

/**
 * Usulan barang baru lewat jalur satu-satunya: permohonan barang baru yang
 * disetujui Pengelola Gudang.
 */
function usulanBarangDariPermohonan(?User $pemohon = null, string $nama = 'Spidol Whiteboard Warna Emas'): ItemProposal
{
    $pemohon ??= User::factory()->role(UserRole::Pengguna)->create();

    $response = test()->actingAs($pemohon)->post(route('item-applications.store'), [
        'purpose' => 'Kebutuhan rapat pleno triwulan IV',
        'items' => [[
            'type' => 'barang_baru',
            'item_name' => $nama,
            'unit' => SatuanBarang::Buah->value,
            'note' => 'Untuk menandai berkas pemeriksaan; spidol yang ada hanya hitam dan merah.',
            'quantity' => 5,
        ]],
    ]);
    $response->assertSessionHasNoErrors()->assertRedirect();

    // Id dibaca dari header Location, bukan `latest('created_at')` yang tidak
    // pasti untuk baris yang lahir pada detik yang sama.
    $id = (string) str($response->headers->get('Location'))->match('#/permohonan/([^/?]+)#');
    $permohonan = ItemApplication::findOrFail($id);

    test()->actingAs(pengelolaGudang())
        ->patch(route('item-applications.approve', $permohonan))
        ->assertSessionHasNoErrors();

    return $permohonan->details()->whereNotNull('item_proposal_id')->sole()->itemProposal;
}

function permohonanUsulanBarang(ItemProposal $proposal): ItemApplication
{
    return $proposal->applicationDetail->application;
}

/**
 * Isian formulir barang yang lengkap, untuk menindaklanjuti sebuah usulan.
 *
 * @return array<string, mixed>
 */
function payloadBarangDariUsulan(ItemProposal $proposal, array $overrides = []): array
{
    return array_merge([
        'item_proposal_id' => $proposal->item_proposal_id,
        'item_code' => 'ATK-900',
        'item_name' => $proposal->item_name,
        'subcategory_id' => $overrides['subcategory_id'] ?? ItemSubcategory::factory()->create()->subcategory_id,
        'unit' => $proposal->unit->value,
        'stock_min' => 5,
        'stock_max' => 50,
        'stock_awal' => 0,
        'is_active' => 1,
    ], $overrides);
}

describe('halaman usulan barang baru dihapus', function () {
    it('tidak lagi menyediakan daftar, formulir, maupun rincian usulan barang', function () {
        $proposal = usulanBarangDariPermohonan();
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)->get('/usulan-barang')->assertNotFound();
        $this->actingAs($admin)->get('/usulan-barang/create')->assertNotFound();
        $this->actingAs($admin)->get("/usulan-barang/{$proposal->item_proposal_id}")->assertNotFound();
        $this->actingAs(pejabatPengadaan())->post('/usulan-barang', [])->assertNotFound();
    });

    it('tidak menampilkan Usulan Barang Baru di menu maupun dasbor', function (UserRole $role) {
        $this->actingAs(User::factory()->role($role)->create())
            ->get('/dasbor')
            ->assertOk()
            ->assertDontSee('href="/usulan-barang"', false)
            ->assertDontSee('Usulan Barang Baru')
            ->assertDontSee('Usulan barang baru');
    })->with([
        'Admin' => [UserRole::Admin],
        'Pengelola Gudang' => [UserRole::PengelolaGudang],
        'Pejabat Pengadaan' => [UserRole::PejabatPengadaan],
        'Kasubbag' => [UserRole::KasubbagUmumTi],
    ]);
});

describe('hak akses keputusan usulan barang', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $proposal = usulanBarangDariPermohonan();
        auth()->logout();

        $this->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", ['decision_note' => 'x'])
            ->assertRedirect('/login');
        $this->get("/barang/create?item_proposal_id={$proposal->item_proposal_id}")
            ->assertRedirect('/login');
    });

    it('menolak peran selain Pengelola Gudang memutuskan usulan', function (UserRole $role) {
        $proposal = usulanBarangDariPermohonan();

        $this->actingAs(User::factory()->role($role)->create())
            ->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", ['decision_note' => 'Tidak perlu.'])
            ->assertForbidden();

        expect($proposal->fresh()->isDiajukan())->toBeTrue();
    })->with([
        'Pejabat Pengadaan' => [UserRole::PejabatPengadaan],
        'Kasubbag' => [UserRole::KasubbagUmumTi],
        'Pengguna' => [UserRole::Pengguna],
    ]);

    it('menampilkan tombol keputusan di rincian permohonan bagi Pengelola Gudang dan Admin', function (UserRole $role) {
        $proposal = usulanBarangDariPermohonan();

        $this->actingAs(User::factory()->role($role)->create())
            ->get(route('item-applications.show', permohonanUsulanBarang($proposal)))
            ->assertOk()
            ->assertSee(route('items.create', ['item_proposal_id' => $proposal->item_proposal_id]), false)
            // URL tolaknya dikodekan @js, jadi dicari lewat nama event-nya.
            ->assertSee('buka-tolak-usulan-barang', false)
            ->assertSee('Daftarkan');
    })->with([
        'Pengelola Gudang' => [UserRole::PengelolaGudang],
        'Admin' => [UserRole::Admin],
    ]);

    it('menyembunyikan tombol keputusan dari pemohon dan pengawas', function (UserRole $role) {
        $pemohon = User::factory()->role($role)->create();
        $proposal = usulanBarangDariPermohonan($pemohon);

        $this->actingAs($pemohon)
            ->get(route('item-applications.show', permohonanUsulanBarang($proposal)))
            ->assertOk()
            ->assertSee('Usulan barang baru · Diajukan')
            ->assertDontSee(route('items.create', ['item_proposal_id' => $proposal->item_proposal_id]), false)
            ->assertDontSee('buka-tolak-usulan-barang', false);
    })->with([
        'Pengguna' => [UserRole::Pengguna],
        'Pejabat Pengadaan' => [UserRole::PejabatPengadaan],
        'Kasubbag' => [UserRole::KasubbagUmumTi],
    ]);

    // Menangkap jebakan middleware: `role:` akan menolak Admin, sedangkan gate
    // meloloskannya lewat Gate::before.
    it('mengizinkan Admin membuka formulir barang dari usulan', function () {
        $proposal = usulanBarangDariPermohonan();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get("/barang/create?item_proposal_id={$proposal->item_proposal_id}")
            ->assertOk();
    });
});

describe('usulan lahir dari permohonan', function () {
    it('menagih Pengelola Gudang dengan tautan ke permohonannya', function () {
        $gudang = pengelolaGudang();
        $proposal = usulanBarangDariPermohonan();

        $notifikasi = Notification::where('user_id', $gudang->user_id)
            ->where('type', NotificationType::UsulanBarangMenungguKeputusan)
            ->first();

        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('Spidol Whiteboard Warna Emas')
            ->and($notifikasi->action_url)->toBe(route('item-applications.show', permohonanUsulanBarang($proposal), false));
    });

    it('menautkan log aktivitas usulan barang ke permohonannya', function () {
        $proposal = usulanBarangDariPermohonan();

        $this->actingAs(pengelolaGudang())->post('/barang', payloadBarangDariUsulan($proposal));

        $log = AuditLog::where('subject_type', 'usulan_barang')->sole();

        expect($log->tautanSubjek())->toBe(route('item-applications.show', permohonanUsulanBarang($proposal)));
    });
});

describe('menyetujui usulan dengan mendaftarkan barangnya', function () {
    it('mengisi formulir barang dari usulan dan kembali ke permohonan saat batal', function () {
        $proposal = usulanBarangDariPermohonan();

        $this->actingAs(pengelolaGudang())
            ->get("/barang/create?item_proposal_id={$proposal->item_proposal_id}")
            ->assertOk()
            ->assertSee('Spidol Whiteboard Warna Emas')
            ->assertSee('Mendaftarkan barang dari usulan', false)
            ->assertSee('href="'.route('item-applications.show', permohonanUsulanBarang($proposal)).'"', false);
    });

    /**
     * Inti ADR 0032: menyetujui usulan *adalah* mendaftarkan barangnya. Tidak
     * ada jalur pembuatan barang kedua, dan tidak ada persetujuan yang berdiri
     * tanpa baris `items`.
     */
    it('mendaftarkan barang berstok nol, menutup usulannya, lalu kembali ke permohonan', function () {
        $gudang = pengelolaGudang();
        $proposal = usulanBarangDariPermohonan();
        $permohonan = permohonanUsulanBarang($proposal);

        $this->actingAs($gudang)
            ->post('/barang', payloadBarangDariUsulan($proposal))
            ->assertRedirect(route('item-applications.show', $permohonan));

        $item = Item::where('item_code', 'ATK-900')->first();
        $proposal->refresh();

        expect($item)->not->toBeNull()
            ->and($item->stock_current)->toBe(0)
            ->and($item->item_name)->toBe('Spidol Whiteboard Warna Emas')
            ->and($proposal->status)->toBe(ItemProposalStatus::Disetujui)
            ->and($proposal->item_id)->toBe($item->item_id)
            ->and($proposal->decided_by)->toBe($gudang->user_id)
            ->and($proposal->decided_at)->not->toBeNull();

        // Stok awal nol tidak menghasilkan mutasi apa pun.
        expect(StockMutation::where('item_id', $item->item_id)->count())->toBe(0);

        expect(AuditLog::where('activity', 'like', '%Menyetujui usulan barang%')->exists())->toBeTrue();

        $this->actingAs($gudang)
            ->get(route('item-applications.show', $permohonan))
            ->assertSee('Usulan barang baru · Disetujui')
            ->assertSee('ATK-900')
            ->assertDontSee('buka-tolak-usulan-barang', false);
    });

    it('memberi tahu pemohon bahwa barangnya sudah terdaftar, menaut ke permohonannya', function () {
        $pemohon = User::factory()->role(UserRole::Pengguna)->create();
        $proposal = usulanBarangDariPermohonan($pemohon);

        $this->actingAs(pengelolaGudang())->post('/barang', payloadBarangDariUsulan($proposal));

        $notifikasi = Notification::where('user_id', $pemohon->user_id)
            ->where('type', NotificationType::UsulanBarangDisetujui)
            ->first();

        // Bukan ke halaman barang: pemohon tidak punya `akses-gudang`.
        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('ATK-900')
            ->and($notifikasi->action_url)->toBe(route('item-applications.show', permohonanUsulanBarang($proposal), false));

        $this->actingAs($pemohon)->get($notifikasi->action_url)->assertOk();
    });

    it('menutup tagihan keputusan begitu usulannya diputuskan', function () {
        $gudang = pengelolaGudang();
        $proposal = usulanBarangDariPermohonan();

        $sebelum = Notification::where('user_id', $gudang->user_id)
            ->where('type', NotificationType::UsulanBarangMenungguKeputusan)
            ->first();

        expect($sebelum)->not->toBeNull();

        $this->actingAs($gudang)->post('/barang', payloadBarangDariUsulan($proposal));

        // Keadaan yang selesai dihapus, bukan ditandai (ADR 0036).
        expect($sebelum->fresh())->toBeNull();
    });

    it('menolak formulir barang yang menunjuk usulan yang sudah diputuskan', function () {
        $gudang = pengelolaGudang();
        $proposal = usulanBarangDariPermohonan();

        $this->actingAs($gudang)->post('/barang', payloadBarangDariUsulan($proposal));

        // Kiriman kedua dari tab lama yang masih terbuka.
        $this->actingAs($gudang)
            ->post('/barang', payloadBarangDariUsulan($proposal, ['item_code' => 'ATK-901']))
            ->assertSessionHasErrors('item_proposal_id');

        expect(Item::where('item_code', 'ATK-901')->exists())->toBeFalse();
    });

    it('mengalihkan formulir barang ke permohonan bila usulannya sudah diputuskan', function () {
        $gudang = pengelolaGudang();
        $proposal = usulanBarangDariPermohonan();

        $this->actingAs($gudang)->post('/barang', payloadBarangDariUsulan($proposal));

        $this->actingAs($gudang)
            ->get("/barang/create?item_proposal_id={$proposal->item_proposal_id}")
            ->assertRedirect(route('item-applications.show', permohonanUsulanBarang($proposal)))
            ->assertSessionHas('error');
    });

    it('mengalihkan ke daftar permohonan bila usulannya tidak ditemukan', function () {
        $this->actingAs(pengelolaGudang())
            ->get('/barang/create?item_proposal_id='.\Illuminate\Support\Str::uuid())
            ->assertRedirect(route('item-applications.index'))
            ->assertSessionHas('error');
    });
});

describe('menolak usulan barang', function () {
    it('menyimpan penolakan, kembali ke permohonan, dan memberi tahu pemohon', function () {
        $gudang = pengelolaGudang();
        $pemohon = User::factory()->role(UserRole::Pengguna)->create();
        $proposal = usulanBarangDariPermohonan($pemohon);
        $permohonan = permohonanUsulanBarang($proposal);

        $this->actingAs($gudang)
            ->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", [
                'decision_note' => 'Barangnya sudah terdaftar sebagai ATK-014 Spidol Permanen Emas.',
            ])
            ->assertRedirect(route('item-applications.show', $permohonan));

        $proposal->refresh();

        expect($proposal->status)->toBe(ItemProposalStatus::Ditolak)
            ->and($proposal->item_id)->toBeNull()
            ->and($proposal->decided_by)->toBe($gudang->user_id)
            ->and($proposal->decision_note)->toContain('ATK-014');

        $notifikasi = Notification::where('user_id', $pemohon->user_id)
            ->where('type', NotificationType::UsulanBarangDitolak)
            ->first();

        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('ATK-014')
            ->and($notifikasi->action_url)->toBe(route('item-applications.show', $permohonan, false));

        // Alasannya tetap terbaca di permohonan setelah notifikasinya terhapus.
        $this->actingAs($pemohon)
            ->get(route('item-applications.show', $permohonan))
            ->assertOk()
            ->assertSee('Usulan barang baru · Ditolak')
            ->assertSee('ATK-014 Spidol Permanen Emas');
    });

    it('mewajibkan keterangan penolakan dan membuka ulang dialognya', function () {
        $gudang = pengelolaGudang();
        $proposal = usulanBarangDariPermohonan();
        $permohonan = permohonanUsulanBarang($proposal);

        $this->actingAs($gudang)
            ->from(route('item-applications.show', $permohonan))
            ->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", [
                'item_proposal_id' => $proposal->item_proposal_id,
                'decision_note' => '',
            ])
            ->assertRedirect(route('item-applications.show', $permohonan))
            ->assertSessionHasErrors('decision_note');

        expect($proposal->fresh()->isDiajukan())->toBeTrue();

        // Dialognya lahir terbuka dan sudah menunjuk usulan yang gagal.
        $this->actingAs($gudang)
            ->get(route('item-applications.show', $permohonan))
            ->assertSee('open: true', false)
            ->assertSee('Jelaskan alasan penolakannya.');
    });

    it('menolak keputusan ganda dengan pesan, bukan galat 500', function () {
        $gudang = pengelolaGudang();
        $proposal = usulanBarangDariPermohonan();

        $this->actingAs($gudang)->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", [
            'decision_note' => 'Sudah ada sebagai ATK-014.',
        ]);

        $this->actingAs($gudang)
            ->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", [
                'decision_note' => 'Keputusan kedua.',
            ])
            ->assertRedirect(route('item-applications.show', permohonanUsulanBarang($proposal)))
            ->assertSessionHas('error');

        expect($proposal->fresh()->decision_note)->toContain('ATK-014');
    });

    it('menutup tagihan keputusan setelah ditolak', function () {
        $gudang = pengelolaGudang();
        $proposal = usulanBarangDariPermohonan();

        $this->actingAs($gudang)->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", [
            'decision_note' => 'Sudah ada sebagai ATK-014.',
        ]);

        $tagihan = Notification::where('user_id', $gudang->user_id)
            ->where('type', NotificationType::UsulanBarangMenungguKeputusan)
            ->first();

        expect($tagihan)->toBeNull();
    });
});

describe('jahitan ke realisasi pengadaan langsung', function () {
    /**
     * Skenario asli yang diminta Kasubbag Umum dan TI, ujung ke ujung:
     * barang belum ada di master, dimohonkan, didaftarkan, lalu langsung
     * diadakan tanpa usulan pengadaan.
     */
    it('mengizinkan barang hasil usulan dipakai pada realisasi langsung', function () {
        $pejabat = pejabatPengadaan();
        $proposal = usulanBarangDariPermohonan($pejabat);

        $this->actingAs(pengelolaGudang())->post('/barang', payloadBarangDariUsulan($proposal));

        $item = $proposal->fresh()->item;

        $procurement = buatRealisasiLangsung(
            $pejabat,
            \App\Models\Supplier::factory()->create(),
            [barisLangsung($item, 24, 12000)],
        );

        expect($procurement->tanpaUsulan())->toBeTrue()
            ->and($procurement->details->first()->item_id)->toBe($item->item_id);
    });
});
