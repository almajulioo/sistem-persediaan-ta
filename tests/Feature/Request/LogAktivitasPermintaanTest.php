<?php

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ItemProposalStatus;
use App\Enums\NotificationType;
use App\Enums\RequestHistoryAction;
use App\Enums\SatuanBarang;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemProposal;
use App\Models\Notification;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\User;
use App\Support\NotificationScanner;
use App\Support\RequestDocumentGenerator;
use Illuminate\Support\Str;

/*
 * Setiap alur permintaan — biasa, darurat, dan umum — meninggalkan jejak di
 * log aktivitas, dan tagihannya ikut disapu pemeriksaan notifikasi harian.
 * Helper bersufiks `Log` supaya tidak bertabrakan dengan helper global berkas
 * uji lain.
 */

function permintaanLog(User $pemohon, Item $item, array $attributes = []): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => $pemohon->user_id,
        'date' => now(),
        'type' => 'normal',
        'status' => 'submitted',
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
        ...$attributes,
    ]);

    RequestDetail::create([
        'request_id' => $request->request_id,
        'item_id' => $item->item_id,
        'quantity_requested' => 3,
    ]);

    return $request;
}

function logPermintaan(AuditEvent $event): ?AuditLog
{
    return AuditLog::where('module', AuditModule::Permintaan)->where('event', $event)->latest('timestamp')->first();
}

beforeEach(function () {
    $this->mock(RequestDocumentGenerator::class)->shouldIgnoreMissing();

    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $this->kasubbag = User::factory()->role(UserRole::KasubbagUmumTi)->create(['unit' => UnitKerja::SubbagianUmumTi]);
    $this->pemohonUmum = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianUmumTi]);
    $this->pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum, 'fullname' => 'Rina Hukum']);
    $this->kertas = Item::factory()->create(['item_name' => 'Kertas A4', 'stock_current' => 10]);
});

describe('permintaan umum', function () {
    it('mencatat pengajuan', function () {
        $this->actingAs($this->pemohonUmum)
            ->post(route('request.umum.store'), ['items' => [['item_id' => $this->kertas->item_id, 'qty' => 2]]])
            ->assertRedirect(route('request.umum.index'));

        $log = logPermintaan(AuditEvent::Ajukan);
        expect($log?->user_id)->toBe($this->pemohonUmum->user_id)
            ->and($log->activity)->toContain('Mengajukan permintaan umum '.ModelsRequest::sole()->request_number)
            ->and($log->subject_id)->toBe(ModelsRequest::sole()->request_id);
    });

    it('mencatat perubahan jumlah beserta alasannya, dan tidak mencatat simpan tanpa perubahan', function () {
        $request = permintaanLog($this->pemohonUmum, $this->kertas, ['type' => 'umum']);
        $this->actingAs($this->gudang);
        $kirim = fn (int $qty, ?string $note) => $this->put(route('request.umum.update', $request), [
            'items' => [['item_id' => $this->kertas->item_id, 'qty' => $qty]],
            'adjustment_note' => $note,
        ])->assertSessionHasNoErrors();

        $kirim(3, null);
        expect(logPermintaan(AuditEvent::Ubah))->toBeNull();

        $kirim(2, 'Disesuaikan dengan sisa anggaran.');
        expect(logPermintaan(AuditEvent::Ubah)?->activity)
            ->toContain($request->request_number)
            ->toContain('Kertas A4 3 → 2')
            ->toContain('Alasan: Disesuaikan dengan sisa anggaran.');
    });

    it('mencatat penolakan beserta alasannya', function () {
        $request = permintaanLog($this->pemohonUmum, $this->kertas, ['type' => 'umum']);

        $this->actingAs($this->gudang)
            ->post(route('request.umum.reject', $request), ['note' => 'Sudah tercakup permintaan lain.'])
            ->assertRedirect(route('request.umum.index'));

        expect(logPermintaan(AuditEvent::Tolak)?->activity)
            ->toBe("Menolak permintaan umum {$request->request_number}. Alasan: Sudah tercakup permintaan lain.");
    });

    it('mencatat penggabungan menjadi permintaan darurat', function () {
        $pertama = permintaanLog($this->pemohonUmum, $this->kertas, ['type' => 'umum', 'request_number' => 'REQ-UMUM-A']);
        $kedua = permintaanLog($this->pemohonUmum, $this->kertas, ['type' => 'umum', 'request_number' => 'REQ-UMUM-B']);

        $this->actingAs($this->gudang)
            ->post(route('request.umum.merge'), ['request_ids' => [$pertama->request_id, $kedua->request_id]])
            ->assertRedirect(route('request.index'));

        $gabungan = ModelsRequest::where('type', 'emergency')->sole();
        $log = logPermintaan(AuditEvent::Tambah);

        expect($log?->user_id)->toBe($this->gudang->user_id)
            ->and($log->subject_id)->toBe($gabungan->request_id)
            ->and($log->activity)->toBe(
                "Menggabung permintaan umum REQ-UMUM-A, REQ-UMUM-B menjadi permintaan darurat {$gabungan->request_number} atas nama {$this->kasubbag->fullname}",
            );
    });
});

describe('pengajuan permintaan', function () {
    it('mencatat permintaan biasa sebagai pengajuan beserta nomor Nota Dinasnya', function () {
        $this->actingAs($this->pemohon)
            ->post(route('request.store'), [
                'items' => [['item_id' => $this->kertas->item_id, 'qty' => 2]],
                'nd_number' => '51/B/ND/DJPKN-V.BLP.1.6/KEU.02.02/11/2026',
            ])
            ->assertSessionHasNoErrors();

        expect(logPermintaan(AuditEvent::Ajukan)?->activity)->toBe(
            'Mengajukan permintaan barang '.ModelsRequest::sole()->request_number.', Nota Dinas 51/B/ND/DJPKN-V.BLP.1.6/KEU.02.02/11/2026',
        );
    });

    it('menyebut permintaan darurat dan pemohon yang diwakili', function () {
        $this->actingAs($this->gudang)
            ->post(route('request.store'), [
                'emergency' => '1',
                'requester_id' => $this->pemohon->user_id,
                'items' => [['item_id' => $this->kertas->item_id, 'qty' => 2]],
                'nd_number' => '52/B/ND/DJPKN-V.BLP.1.6/KEU.02.02/11/2026',
            ])
            ->assertSessionHasNoErrors();

        $log = logPermintaan(AuditEvent::Ajukan);
        expect($log?->user_id)->toBe($this->gudang->user_id)
            ->and($log->activity)->toBe(sprintf(
                'Membuat permintaan darurat %s atas nama Rina Hukum (%s), Nota Dinas 52/B/ND/DJPKN-V.BLP.1.6/KEU.02.02/11/2026',
                ModelsRequest::sole()->request_number,
                UnitKerja::SubbagianHukum->label(),
            ));
    });
});

describe('menghapus usulan barang yang ditolak', function () {
    it('mencatat di log dan riwayat, lalu mengabari pemohon', function () {
        $request = permintaanLog($this->pemohon, $this->kertas, ['status' => 'approved_by_head']);
        $proposal = ItemProposal::create([
            'item_name' => 'Map Plastik Biru',
            'unit' => SatuanBarang::cases()[0],
            'purpose_note' => 'Untuk arsip berkas perkara.',
            'proposed_by' => $this->pemohon->user_id,
            'status' => ItemProposalStatus::Diajukan,
        ]);
        $proposal->forceFill(['status' => ItemProposalStatus::Ditolak])->save();
        $detail = RequestDetail::create([
            'request_id' => $request->request_id,
            'item_proposal_id' => $proposal->item_proposal_id,
            'quantity_requested' => 2,
        ]);

        $this->actingAs($this->gudang)
            ->delete(route('request.proposal-detail.remove', [$request, $detail]))
            ->assertRedirect(route('request.show', $request));

        $history = $request->histories()->sole();

        expect(RequestDetail::find($detail->getKey()))->toBeNull()
            ->and($request->refresh()->status)->toBe('approved_by_head')
            ->and($history->action)->toBe(RequestHistoryAction::UsulanDihapus)
            ->and($history->note)->toContain('Map Plastik Biru')
            ->and(logPermintaan(AuditEvent::Hapus)?->activity)
            ->toBe("Menghapus usulan barang baru yang ditolak \"Map Plastik Biru\" dari permintaan {$request->request_number}")
            ->and(Notification::where('user_id', $this->pemohon->user_id)
                ->where('type', NotificationType::PermintaanDiperbarui)->first()?->body)
            ->toContain('Map Plastik Biru');
    });
});

describe('menghapus usulan dari permintaan lain', function () {
    it('menolak baris yang bukan milik permintaan pada alamatnya', function () {
        $milikLain = permintaanLog($this->pemohon, $this->kertas);
        $permintaan = permintaanLog($this->pemohon, $this->kertas);
        $proposal = ItemProposal::create([
            'item_name' => 'Map Plastik Biru',
            'unit' => SatuanBarang::cases()[0],
            'purpose_note' => 'Untuk arsip berkas perkara.',
            'proposed_by' => $this->pemohon->user_id,
            'status' => ItemProposalStatus::Diajukan,
        ]);
        $proposal->forceFill(['status' => ItemProposalStatus::Ditolak])->save();
        $detail = RequestDetail::create([
            'request_id' => $milikLain->request_id,
            'item_proposal_id' => $proposal->item_proposal_id,
            'quantity_requested' => 2,
        ]);

        $this->actingAs($this->gudang)
            ->delete(route('request.proposal-detail.remove', [$permintaan, $detail]))
            ->assertNotFound();

        expect(RequestDetail::find($detail->getKey()))->not->toBeNull();
    });
});

describe('pemeriksaan notifikasi harian', function () {
    it('menagih Kepala Unit yang baru diangkat atas permintaan yang sudah menunggu', function () {
        $request = permintaanLog($this->pemohon, $this->kertas);
        NotificationScanner::periksaPermintaan($request);

        $kepalaBaru = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianHukum]);
        $tagihan = fn () => Notification::where('user_id', $kepalaBaru->user_id)
            ->where('type', NotificationType::PermintaanDiajukan)->exists();

        expect($tagihan())->toBeFalse();

        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect($tagihan())->toBeTrue();
    });

    it('menutup tagihan permintaan yang tahapnya sudah lewat', function () {
        $request = permintaanLog($this->pemohon, $this->kertas, ['status' => 'approved_by_head']);
        NotificationScanner::periksaPermintaan($request);
        $tagihan = fn () => Notification::where('user_id', $this->gudang->user_id)
            ->where('type', NotificationType::PermintaanDisetujuiKepalaUnit)->exists();

        expect($tagihan())->toBeTrue();

        // Tahap berpindah tanpa pemeriksaan reaktif, mis. karena gagal.
        ModelsRequest::whereKey($request->getKey())->update(['status' => 'completed']);
        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect($tagihan())->toBeFalse();
    });
});
