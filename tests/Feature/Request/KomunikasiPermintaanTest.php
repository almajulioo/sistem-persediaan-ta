<?php

use App\Enums\NotificationType;
use App\Enums\RequestHistoryAction;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\RequestHistory;
use App\Models\User;
use App\Support\RequestDocumentGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Komunikasi dua arah pada alur permintaan: setiap keputusan petugas tercatat
 * di riwayat beserta alasannya dan sampai ke pemohon. Helper bersufiks
 * `Komunikasi` supaya tidak bertabrakan dengan helper global berkas lain.
 */

/**
 * @param  list<array{0: Item, 1: int, 2?: int|null}>  $lines  [barang, diminta, disetujui]
 */
function permintaanKomunikasi(string $status, User $pemohon, array $lines, array $attributes = []): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => $pemohon->user_id,
        'date' => now(),
        'type' => 'normal',
        'status' => $status,
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
        ...$attributes,
    ]);

    foreach ($lines as $line) {
        RequestDetail::create([
            'request_id' => $request->request_id,
            'item_id' => $line[0]->item_id,
            'quantity_requested' => $line[1],
            'quantity_approved' => $line[2] ?? null,
        ]);
    }

    return $request;
}

function responKomunikasi(ModelsRequest $request, string $action, array $data = [])
{
    return test()
        ->from(route('request.show', $request))
        ->post(route('request.respond', $request), ['action' => $action, ...$data]);
}

function notifikasiKomunikasi(User $user, NotificationType $type): ?Notification
{
    return Notification::where('user_id', $user->user_id)->where('type', $type)->latest('created_at')->first();
}

function ubahKomunikasi(ModelsRequest $request, array $qty, ?string $note)
{
    return test()
        ->from(route('request.edit', $request))
        ->put(route('request.update', $request), [
            'items' => collect($qty)->map(fn (int $value, string $itemId) => ['item_id' => $itemId, 'qty' => $value])->values()->all(),
            'nd_sequence' => '51',
            'adjustment_note' => $note,
        ]);
}

function umumKomunikasi(User $pemohon, Item $item, int $qty = 3): ModelsRequest
{
    return permintaanKomunikasi('submitted', $pemohon, [[$item, $qty]], [
        'type' => 'umum',
        'request_number' => 'REQ-UMUM-'.Str::upper(Str::random(8)),
    ]);
}

beforeEach(function () {
    $this->mock(RequestDocumentGenerator::class)->shouldIgnoreMissing();

    $this->pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum]);
    $this->kepala = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianHukum]);
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $this->kasubbag = User::factory()->role(UserRole::KasubbagUmumTi)->create(['unit' => UnitKerja::SubbagianUmumTi]);
    $this->kertas = Item::factory()->create(['item_name' => 'Kertas A4', 'stock_current' => 10]);
    $this->pulpen = Item::factory()->create(['item_name' => 'Pulpen Hitam', 'stock_current' => 10]);
});

describe('menolak', function () {
    it('mewajibkan alasan', function () {
        $request = permintaanKomunikasi('submitted', $this->pemohon, [[$this->kertas, 5]]);

        $this->actingAs($this->kepala);
        responKomunikasi($request, 'reject', ['note' => '  '])->assertSessionHasErrors('note');

        expect($request->refresh()->status)->toBe('submitted')
            ->and(RequestHistory::count())->toBe(0);
    });

    it('mencatat alasan di riwayat dan mengirimkannya ke pemohon', function () {
        $request = permintaanKomunikasi('submitted', $this->pemohon, [[$this->kertas, 5]]);

        $this->actingAs($this->kepala);
        responKomunikasi($request, 'reject', ['note' => 'Sudah diberikan bulan lalu.'])->assertSessionHasNoErrors();

        $history = $request->histories()->sole();
        expect($request->refresh()->status)->toBe('rejected')
            ->and($history->action)->toBe(RequestHistoryAction::Ditolak)
            ->and($history->user_id)->toBe($this->kepala->user_id)
            ->and($history->note)->toBe('Sudah diberikan bulan lalu.');

        expect(notifikasiKomunikasi($this->pemohon, NotificationType::PermintaanDitolak)?->body)
            ->toContain($this->kepala->fullname)
            ->toContain('Sudah diberikan bulan lalu.');

        $this->actingAs($this->pemohon)
            ->get(route('request.show', $request))
            ->assertOk()
            ->assertSee('Riwayat &amp; Catatan', false)
            ->assertSee('Sudah diberikan bulan lalu.');
    });

    it('membolehkan permintaan darurat yang sudah disetujui Kepala Unit ditolak', function () {
        $request = permintaanKomunikasi('approved_by_head', $this->pemohon, [[$this->kertas, 5]], ['type' => 'emergency']);

        $this->actingAs($this->gudang);
        responKomunikasi($request, 'reject', ['note' => 'Stok habis, menunggu pengadaan.'])->assertSessionHasNoErrors();

        expect($request->refresh()->status)->toBe('rejected');
    });
});

describe('menyetujui', function () {
    it('mencatat catatan opsional dan mengabari pemohon tiap tahap', function () {
        $request = permintaanKomunikasi('submitted', $this->pemohon, [[$this->kertas, 5]]);

        $this->actingAs($this->kepala);
        responKomunikasi($request, 'approve', ['note' => 'Prioritaskan untuk rapat pleno.'])->assertSessionHasNoErrors();

        $history = $request->histories()->sole();
        expect($history->judul())->toBe('Disetujui Kepala Subbagian')
            ->and(notifikasiKomunikasi($this->pemohon, NotificationType::PermintaanDiperbarui)?->body)
            ->toContain('Prioritaskan untuk rapat pleno.');

        // Catatan Kepala Unit ikut terbaca oleh Gudang yang bertindak berikutnya.
        expect(notifikasiKomunikasi($this->gudang, NotificationType::PermintaanDisetujuiKepalaUnit)?->body)
            ->toContain('Prioritaskan untuk rapat pleno.');
    });
});

describe('ubah kuantitas', function () {
    beforeEach(function () {
        $this->request = permintaanKomunikasi('approved_by_head', $this->pemohon, [
            [$this->kertas, 8],
            [$this->pulpen, 4],
        ], ['nd_number' => '51/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026']);
        $this->actingAs($this->gudang);
    });

    it('mewajibkan alasan bila ada jumlah yang berubah', function () {
        ubahKomunikasi($this->request, [$this->kertas->item_id => 5, $this->pulpen->item_id => 4], null)
            ->assertSessionHasErrors('adjustment_note');

        expect($this->request->refresh()->status)->toBe('approved_by_head');
    });

    it('tidak mewajibkan alasan bila jumlahnya sesuai permintaan', function () {
        ubahKomunikasi($this->request, [$this->kertas->item_id => 8, $this->pulpen->item_id => 4], null)
            ->assertSessionHasNoErrors();

        expect($this->request->histories()->sole()->action)->toBe(RequestHistoryAction::Disetujui);
    });

    it('menolak bila tidak ada satu pun barang yang diberikan', function () {
        ubahKomunikasi($this->request, [$this->kertas->item_id => 0, $this->pulpen->item_id => 0], 'Stok habis.')
            ->assertSessionHasErrors('items');
    });

    it('mencatat rincian perubahan per barang beserta alasannya', function () {
        ubahKomunikasi($this->request, [$this->kertas->item_id => 5, $this->pulpen->item_id => 0], 'Stok kertas tinggal 5 rim.')
            ->assertSessionHasNoErrors();

        $history = $this->request->histories()->sole();
        expect($this->request->refresh()->status)->toBe('processed_by_staff')
            ->and($history->action)->toBe(RequestHistoryAction::KuantitasDiubah)
            ->and($history->rincianPerubahan())->toBe(['Kertas A4: 8 → 5', 'Pulpen Hitam: 4 → 0 (tidak diberikan)']);

        expect(notifikasiKomunikasi($this->pemohon, NotificationType::PermintaanDiperbarui)?->body)
            ->toContain('Kertas A4: 8 → 5')
            ->toContain('Stok kertas tinggal 5 rim.');

        $this->actingAs($this->pemohon)
            ->get(route('request.show', $this->request))
            ->assertSee('Disetujui')
            ->assertSee('0 (tidak diberikan)');
    });

    it('membuka ubah kuantitas permintaan darurat lalu menyelesaikannya sesuai jumlah baru', function () {
        $this->request->update(['type' => 'emergency']);

        ubahKomunikasi($this->request, [$this->kertas->item_id => 3, $this->pulpen->item_id => 0], 'Stok sedang menipis.')
            ->assertRedirect(route('request.show', $this->request));

        expect($this->request->refresh()->status)->toBe('approved_by_head');

        responKomunikasi($this->request, 'approve')->assertSessionHasNoErrors();

        expect($this->request->refresh()->status)->toBe('delivered')
            ->and($this->kertas->refresh()->stock_current)->toBe(7)
            ->and($this->pulpen->refresh()->stock_current)->toBe(10);
    });
});

describe('BAST', function () {
    it('hanya memuat barang yang benar-benar diserahkan', function () {
        Storage::fake('local');
        $this->app->instance(RequestDocumentGenerator::class, new RequestDocumentGenerator);
        $request = permintaanKomunikasi('in_issuance', $this->pemohon, [
            [$this->kertas, 8, 0],
            [$this->pulpen, 4, 3],
        ], ['bast_number' => '51/B/BAST/DJPKN-V.BLP.1.5/KEU.02.02/11/2026']);

        $this->actingAs($this->gudang);
        responKomunikasi($request, 'approve')->assertSessionHasNoErrors();

        expect($this->kertas->refresh()->stock_current)->toBe(10)
            ->and($this->pulpen->refresh()->stock_current)->toBe(7);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path("bast-permintaan/{$request->request_id}.docx"));
        $isi = $zip->getFromName('word/document.xml');
        $zip->close();

        expect($isi)->toContain('Pulpen Hitam')->not->toContain('Kertas A4');
    });
});

describe('penerima notifikasi', function () {
    it('menagih Kasubbag saat permintaan unit Subbagian Umum dan TI diajukan', function () {
        $pemohonUmumTi = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianUmumTi]);
        $request = permintaanKomunikasi('submitted', $pemohonUmumTi, [[$this->kertas, 2]]);

        \App\Support\NotificationScanner::periksaPermintaan($request);

        expect(notifikasiKomunikasi($this->kasubbag, NotificationType::PermintaanDiajukan))->not->toBeNull();
    });

    it('menagih Kasubbag setelah Gudang menyetujui, lalu Gudang saat dalam pengeluaran', function () {
        $request = permintaanKomunikasi('approved_by_head', $this->pemohon, [[$this->kertas, 2]], [
            'nd_number' => '7/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
        ]);

        $this->actingAs($this->gudang);
        responKomunikasi($request, 'approve')->assertSessionHasNoErrors();
        expect(notifikasiKomunikasi($this->kasubbag, NotificationType::PermintaanDisetujuiPengelolaGudang))->not->toBeNull();

        $this->actingAs($this->kasubbag);
        responKomunikasi($request, 'approve')->assertSessionHasNoErrors();

        expect($request->refresh()->status)->toBe('in_issuance')
            ->and(notifikasiKomunikasi($this->gudang, NotificationType::PermintaanDalamPengeluaran))->not->toBeNull()
            ->and(notifikasiKomunikasi($this->kasubbag, NotificationType::PermintaanDisetujuiPengelolaGudang))->toBeNull();
    });
});

describe('kembalikan status', function () {
    it('mencatat alasan Admin di riwayat yang terlihat pemohon', function () {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $request = permintaanKomunikasi('processed_by_staff', $this->pemohon, [[$this->kertas, 2, 2]]);

        $this->actingAs($admin)
            ->post(route('request.revert-status', $request), ['reason' => 'Salah klik oleh staf.'])
            ->assertSessionHasNoErrors();

        expect($request->histories()->sole()->judul())
            ->toBe('Status dikembalikan dari Disetujui Pengelola Gudang ke Disetujui Kepala Subbagian');

        $this->actingAs($this->pemohon)
            ->get(route('request.show', $request))
            ->assertSee('Salah klik oleh staf.');
    });
});

describe('permintaan umum', function () {
    beforeEach(function () {
        $this->pemohonUmum = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianUmumTi]);
    });

    it('memberi tahu Gudang saat permintaan umum baru diajukan', function () {
        $this->actingAs($this->pemohonUmum)
            ->post(route('request.umum.store'), ['items' => [['item_id' => $this->kertas->item_id, 'qty' => 2]]])
            ->assertRedirect(route('request.umum.index'));

        expect(notifikasiKomunikasi($this->gudang, NotificationType::PermintaanUmumMenunggu))->not->toBeNull()
            ->and(ModelsRequest::sole()->histories()->sole()->action)->toBe(RequestHistoryAction::Diajukan);
    });

    it('menampilkan permintaan umum milik pemohon sendiri, baca-saja', function () {
        $milikSendiri = umumKomunikasi($this->pemohonUmum, $this->kertas);
        $milikLain = umumKomunikasi(User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianUmumTi]), $this->pulpen);

        $this->actingAs($this->pemohonUmum)
            ->get(route('request.umum.index'))
            ->assertOk()
            ->assertSee($milikSendiri->request_number)
            ->assertDontSee($milikLain->request_number)
            ->assertDontSee('Tolak permintaan');

        $this->actingAs($this->pemohon)->get(route('request.umum.index'))->assertForbidden();
    });

    it('mewajibkan alasan penolakan dan mengirimkannya ke pemohon', function () {
        $request = umumKomunikasi($this->pemohonUmum, $this->kertas);
        $this->actingAs($this->gudang);

        $this->post(route('request.umum.reject', $request), ['note' => ''])->assertSessionHasErrors('note');
        expect($request->refresh()->status)->toBe('submitted');

        $this->post(route('request.umum.reject', $request), ['note' => 'Sudah tercakup permintaan lain.'])
            ->assertRedirect(route('request.umum.index'));

        expect($request->refresh()->status)->toBe('rejected')
            ->and(notifikasiKomunikasi($this->pemohonUmum, NotificationType::PermintaanDitolak)?->body)
            ->toContain('Sudah tercakup permintaan lain.');
    });

    it('mewajibkan alasan saat Gudang mengubah isi permintaan umum', function () {
        $request = umumKomunikasi($this->pemohonUmum, $this->kertas, 3);
        $this->actingAs($this->gudang);
        $kirim = fn (?string $note) => $this->put(route('request.umum.update', $request), [
            'items' => [['item_id' => $this->kertas->item_id, 'qty' => 2]],
            'adjustment_note' => $note,
        ]);

        $kirim(null)->assertSessionHasErrors('adjustment_note');
        $kirim('Disesuaikan dengan sisa anggaran.')->assertSessionHasNoErrors();

        expect($request->histories()->sole()->rincianPerubahan())->toBe(['Kertas A4: 3 → 2']);
    });

    it('memberi tahu pemohon asal saat permintaannya digabung', function () {
        $request = umumKomunikasi($this->pemohonUmum, $this->kertas);

        $this->actingAs($this->gudang)
            ->post(route('request.umum.merge'), ['request_ids' => [$request->request_id]])
            ->assertRedirect(route('request.index'));

        $gabungan = ModelsRequest::where('type', 'emergency')->sole();

        expect($request->histories()->sole()->action)->toBe(RequestHistoryAction::Digabung)
            ->and($gabungan->histories()->sole()->note)->toContain($request->request_number)
            ->and(notifikasiKomunikasi($this->pemohonUmum, NotificationType::PermintaanDiperbarui)?->body)
            ->toContain($gabungan->request_number)
            ->and(notifikasiKomunikasi($this->gudang, NotificationType::PermintaanUmumMenunggu))->toBeNull();
    });

    it('tidak bisa diproses lewat alur persetujuan biasa', function () {
        $request = umumKomunikasi($this->pemohonUmum, $this->kertas);

        $this->actingAs($this->kasubbag);
        responKomunikasi($request, 'approve')->assertSessionHas('error', 'Permintaan umum diproses lewat Daftar Permintaan Umum.');

        expect($request->refresh()->status)->toBe('submitted');
    });
});
