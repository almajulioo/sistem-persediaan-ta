<?php

use App\Enums\NotificationType;
use App\Enums\RequestHistoryAction;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\User;
use App\Support\NotificationScanner;
use App\Support\RequestDocumentGenerator;
use Illuminate\Support\Str;

/*
 * Akhir alur permintaan: barang diserahkan Gudang (stok keluar), lalu pemohon
 * mengonfirmasi penerimaannya. Helper bersufiks `Penerimaan` supaya tidak
 * bertabrakan dengan helper global berkas uji lain.
 */

function permintaanPenerimaan(string $status, User $pemohon, Item $item, array $attributes = []): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => $pemohon->user_id,
        'date' => now(),
        'type' => 'normal',
        'status' => $status,
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
        'nd_number' => Str::random(6).'/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
        ...$attributes,
    ]);

    RequestDetail::create([
        'request_id' => $request->request_id,
        'item_id' => $item->item_id,
        'quantity_requested' => 4,
        'quantity_approved' => 4,
    ]);

    return $request;
}

function penerimaan(ModelsRequest $request, string $action, ?string $note = null)
{
    return test()
        ->from(route('request.show', $request))
        ->post(route('request.receipt', $request), array_filter(
            ['action' => $action, 'note' => $note],
            fn ($value) => $value !== null,
        ));
}

function tagihanPenerimaan(User $user, NotificationType $type): ?Notification
{
    return Notification::where('user_id', $user->user_id)->where('type', $type)->latest('created_at')->first();
}

beforeEach(function () {
    $this->mock(RequestDocumentGenerator::class)->shouldIgnoreMissing();

    $this->pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum]);
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $this->item = Item::factory()->create(['item_name' => 'Kertas A4', 'stock_current' => 10]);
});

describe('menyerahkan', function () {
    it('mengeluarkan stok dan menagih konfirmasi pemohon tanpa langsung selesai', function () {
        $request = permintaanPenerimaan('in_issuance', $this->pemohon, $this->item);

        $this->actingAs($this->gudang)
            ->post(route('request.respond', $request), ['action' => 'approve', 'note' => 'Silakan ambil di gudang lantai 1.'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        expect($request->status)->toBe('delivered')
            ->and($request->delivered_at)->not->toBeNull()
            ->and($this->item->refresh()->stock_current)->toBe(6)
            ->and(tagihanPenerimaan($this->pemohon, NotificationType::PermintaanMenungguKonfirmasi)?->body)
            ->toContain('Silakan ambil di gudang lantai 1.')
            ->and(tagihanPenerimaan($this->pemohon, NotificationType::PermintaanSelesai))->toBeNull()
            ->and(tagihanPenerimaan($this->pemohon, NotificationType::PermintaanDiperbarui))->toBeNull();
    });

    it('menyerahkan giliran ke Pengelola Gudang, bukan Kasubbag, setelah pengeluaran dimulai', function () {
        $kasubbag = User::factory()->role(UserRole::KasubbagUmumTi)->create(['unit' => UnitKerja::SubbagianUmumTi]);
        $request = permintaanPenerimaan('in_issuance', $this->pemohon, $this->item);

        $this->actingAs($kasubbag)
            ->get(route('request.show', $request))
            ->assertOk()
            ->assertDontSee('Serahkan Barang');

        $this->actingAs($kasubbag)
            ->from(route('request.show', $request))
            ->post(route('request.respond', $request), ['action' => 'approve'])
            ->assertSessionHas('error');

        expect($request->refresh()->status)->toBe('in_issuance')
            ->and($this->item->refresh()->stock_current)->toBe(10);

        $this->actingAs($this->gudang)
            ->get(route('request.show', $request))
            ->assertSee('Serahkan Barang');
    });

    it('memperlakukan permintaan darurat yang diserahkan sama', function () {
        $request = permintaanPenerimaan('approved_by_head', $this->pemohon, $this->item, ['type' => 'emergency']);
        $request->item()->update(['quantity_approved' => null]);

        $this->actingAs($this->gudang)
            ->post(route('request.respond', $request), ['action' => 'approve'])
            ->assertSessionHasNoErrors();

        expect($request->refresh()->status)->toBe('delivered')
            ->and($this->item->refresh()->stock_current)->toBe(6);
    });

    it('tidak bisa ditolak maupun dikembalikan statusnya setelah diserahkan', function () {
        $request = permintaanPenerimaan('delivered', $this->pemohon, $this->item, ['delivered_at' => now()]);

        $this->actingAs($this->gudang)
            ->post(route('request.respond', $request), ['action' => 'reject', 'note' => 'Salah.'])
            ->assertSessionHas('error');

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post(route('request.revert-status', $request), ['reason' => 'Salah klik.'])
            ->assertSessionHas('error');

        expect($request->refresh()->status)->toBe('delivered');
    });
});

describe('konfirmasi pemohon', function () {
    beforeEach(function () {
        $this->request = permintaanPenerimaan('delivered', $this->pemohon, $this->item, ['delivered_at' => now()]);
        NotificationScanner::periksaPermintaan($this->request);
    });

    it('menampilkan tombol konfirmasi hanya kepada pemohon', function () {
        $this->actingAs($this->pemohon)
            ->get(route('request.show', $this->request))
            ->assertOk()
            ->assertSee('Konfirmasi Diterima')
            ->assertSee('Laporkan Masalah');

        $this->actingAs($this->gudang)
            ->get(route('request.show', $this->request))
            ->assertOk()
            ->assertDontSee('Konfirmasi Diterima')
            ->assertSee('dapat ditutup atas namanya mulai');
    });

    it('menutup permintaan dan memadamkan tagihannya', function () {
        $this->actingAs($this->pemohon);
        penerimaan($this->request, 'confirm', 'Diterima lengkap.')->assertSessionHasNoErrors();

        expect($this->request->refresh()->status)->toBe('completed')
            ->and($this->request->histories()->sole()->action)->toBe(RequestHistoryAction::Diterima)
            ->and(tagihanPenerimaan($this->pemohon, NotificationType::PermintaanMenungguKonfirmasi))->toBeNull()
            ->and($this->item->refresh()->stock_current)->toBe(10);
    });

    it('hanya boleh dilakukan pemohon', function (UserRole $role) {
        $this->actingAs(User::factory()->role($role)->create(['unit' => UnitKerja::SubbagianHukum]));
        penerimaan($this->request, 'confirm')
            ->assertSessionHas('error', 'Hanya pemohon yang dapat mengonfirmasi penerimaan barangnya.');

        expect($this->request->refresh()->status)->toBe('delivered');
    })->with([UserRole::PengelolaGudang, UserRole::Admin, UserRole::Pengguna, UserRole::KepalaUnit]);
});

describe('laporan masalah', function () {
    beforeEach(function () {
        $this->request = permintaanPenerimaan('delivered', $this->pemohon, $this->item, ['delivered_at' => now()]);
    });

    it('wajib berketerangan, menagih Gudang, dan padam setelah ditanggapi', function () {
        $this->actingAs($this->pemohon);
        penerimaan($this->request, 'report', ' ')->assertSessionHasErrors('note');
        penerimaan($this->request, 'report', 'Kertas yang diterima kurang 2 rim.')->assertSessionHasNoErrors();

        expect($this->request->refresh()->status)->toBe('delivered')
            ->and($this->request->hasOpenReceiptProblem())->toBeTrue()
            ->and(tagihanPenerimaan($this->gudang, NotificationType::PermintaanMasalahPenerimaan)?->body)
            ->toContain('Kertas yang diterima kurang 2 rim.');

        $this->actingAs($this->gudang);
        penerimaan($this->request, 'reply')->assertSessionHasErrors('note');
        penerimaan($this->request, 'reply', 'Kekurangan 2 rim sudah diserahkan.')->assertSessionHasNoErrors();

        expect($this->request->hasOpenReceiptProblem())->toBeFalse()
            ->and(tagihanPenerimaan($this->gudang, NotificationType::PermintaanMasalahPenerimaan))->toBeNull()
            ->and(tagihanPenerimaan($this->pemohon, NotificationType::PermintaanDiperbarui)?->body)
            ->toContain('Kekurangan 2 rim sudah diserahkan.');

        $this->actingAs($this->pemohon);
        penerimaan($this->request, 'confirm')->assertSessionHasNoErrors();
        expect($this->request->refresh()->status)->toBe('completed');
    });

    it('tidak bisa ditanggapi bila tidak ada laporan', function () {
        $this->actingAs($this->gudang);
        penerimaan($this->request, 'reply', 'Tanggapan.')
            ->assertSessionHas('error', 'Tidak ada laporan masalah yang perlu ditanggapi.');
    });
});

describe('tutup atas nama pemohon', function () {
    it('ditolak sebelum batas hari', function () {
        $request = permintaanPenerimaan('delivered', $this->pemohon, $this->item, ['delivered_at' => now()->subDays(6)]);

        $this->actingAs($this->gudang);
        penerimaan($request, 'close', 'Pemohon tidak merespons.')->assertSessionHas('error');

        expect($request->refresh()->status)->toBe('delivered');
    });

    it('ditolak selama masih ada masalah yang belum ditanggapi', function () {
        $request = permintaanPenerimaan('delivered', $this->pemohon, $this->item, ['delivered_at' => now()->subDays(10)]);
        $this->actingAs($this->pemohon);
        penerimaan($request, 'report', 'Barang belum sampai.');

        $this->actingAs($this->gudang);
        penerimaan($request, 'close', 'Pemohon tidak merespons.')->assertSessionHas('error');

        expect($request->refresh()->status)->toBe('delivered');
    });

    it('menutup dengan alasan setelah batas hari dan mengabari pemohon', function () {
        $request = permintaanPenerimaan('delivered', $this->pemohon, $this->item, [
            'delivered_at' => now()->subDays(ModelsRequest::BATAS_HARI_KONFIRMASI),
        ]);

        $this->actingAs($this->gudang);
        penerimaan($request, 'close')->assertSessionHasErrors('note');
        penerimaan($request, 'close', 'Barang diambil langsung oleh pemohon.')->assertSessionHasNoErrors();

        expect($request->refresh()->status)->toBe('completed')
            ->and($request->histories()->sole()->action)->toBe(RequestHistoryAction::DitutupPetugas)
            ->and(tagihanPenerimaan($this->pemohon, NotificationType::PermintaanSelesai)?->body)
            ->toContain('Barang diambil langsung oleh pemohon.');
    });

    it('tidak boleh dilakukan pemohon sendiri', function () {
        $request = permintaanPenerimaan('delivered', $this->pemohon, $this->item, ['delivered_at' => now()->subDays(30)]);

        $this->actingAs($this->pemohon);
        penerimaan($request, 'close', 'Tutup saja.')->assertSessionHas('error');

        expect($request->refresh()->status)->toBe('delivered');
    });
});
