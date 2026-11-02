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
use App\Support\StockReservation;
use Illuminate\Support\Str;

/*
 * Stok tertahan: permintaan yang masih berjalan menahan jumlahnya, sehingga
 * permintaan baru hanya boleh mengambil dari stok tersedia. Helper bersufiks
 * `Tahan` supaya tidak bertabrakan dengan helper global berkas lain.
 */

function permintaanTahan(User $pemohon, string $status, Item $item, int $diminta, ?int $disetujui = null, array $attributes = []): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => $pemohon->user_id,
        'date' => now(),
        'type' => 'normal',
        'status' => $status,
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
        ...$attributes,
    ]);

    RequestDetail::create([
        'request_id' => $request->request_id,
        'item_id' => $item->item_id,
        'quantity_requested' => $diminta,
        'quantity_approved' => $disetujui,
    ]);

    return $request;
}

function ajukanTahan(User $pemohon, Item $item, int $qty)
{
    return test()->actingAs($pemohon)
        ->from(route('request.create'))
        ->post(route('request.store'), [
            'nd_sequence' => (string) random_int(1, 999999),
            'items' => [['item_id' => $item->item_id, 'qty' => $qty]],
        ]);
}

beforeEach(function () {
    $this->mock(RequestDocumentGenerator::class)->shouldIgnoreMissing();

    $this->pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum]);
    $this->lain = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianSdm]);
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $this->kertas = Item::factory()->create(['item_name' => 'Kertas A4', 'stock_current' => 10]);
});

describe('perhitungan', function () {
    it('hanya menghitung permintaan yang masih berjalan', function () {
        permintaanTahan($this->lain, 'submitted', $this->kertas, 3);
        permintaanTahan($this->lain, 'approved_by_head', $this->kertas, 2);
        // Sudah diputuskan Gudang: yang ditahan jumlah keputusannya.
        permintaanTahan($this->lain, 'processed_by_staff', $this->kertas, 5, 1);
        // Stok fisiknya sudah berkurang, atau tidak lagi berjalan.
        permintaanTahan($this->lain, 'delivered', $this->kertas, 4, 4);
        permintaanTahan($this->lain, 'rejected', $this->kertas, 4);
        permintaanTahan($this->lain, 'submitted', $this->kertas, 4, attributes: ['type' => 'umum']);

        expect(StockReservation::reservedFor([$this->kertas->item_id])->all())->toBe([$this->kertas->item_id => 6])
            ->and(StockReservation::availableFor($this->kertas))->toBe(4);
    });
});

describe('mengajukan', function () {
    it('menolak jumlah di atas stok tersedia dan menyebut yang ditahan', function () {
        permintaanTahan($this->lain, 'submitted', $this->kertas, 8);

        ajukanTahan($this->pemohon, $this->kertas, 3)->assertSessionHasErrors('items.0.qty');
        expect(session('errors')->first('items.0.qty'))->toContain('stok tersedia (2)')->toContain('8 ditahan');

        ajukanTahan($this->pemohon, $this->kertas, 2)->assertSessionHasNoErrors();
    });

    it('melepas tahanan permintaan yang ditolak', function () {
        permintaanTahan($this->lain, 'submitted', $this->kertas, 8)->update(['status' => 'rejected']);

        ajukanTahan($this->pemohon, $this->kertas, 10)->assertSessionHasNoErrors();
    });

    it('menampilkan stok tersedia dan yang ditahan di formulir', function () {
        permintaanTahan($this->lain, 'submitted', $this->kertas, 8);

        $response = $this->actingAs($this->pemohon)->get(route('request.create'))->assertOk();
        $kertas = collect($response->viewData('items'))->firstWhere('item_id', $this->kertas->item_id);

        expect($kertas['available'])->toBe(2)->and($kertas['reserved'])->toBe(8);
        $response->assertSee('ditahan permintaan lain');
    });
});

describe('gudang memproses', function () {
    it('ubah kuantitas tidak berebut dengan tahanannya sendiri', function () {
        $request = permintaanTahan($this->pemohon, 'approved_by_head', $this->kertas, 8, attributes: [
            'nd_number' => '51/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
        ]);

        $this->actingAs($this->gudang)
            ->put(route('request.update', $request), [
                'items' => [['item_id' => $this->kertas->item_id, 'qty' => 8]],
                'nd_sequence' => '51',
            ])
            ->assertSessionHasNoErrors();

        expect($request->refresh()->status)->toBe('processed_by_staff');
    });

    it('menyetujui gagal bila tahanan permintaan lain membuat stok tersedia kurang', function () {
        permintaanTahan($this->lain, 'in_issuance', $this->kertas, 5, 5);
        $request = permintaanTahan($this->pemohon, 'approved_by_head', $this->kertas, 8, attributes: [
            'nd_number' => '52/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
        ]);

        $this->actingAs($this->gudang)
            ->from(route('request.show', $request))
            ->post(route('request.respond', $request), ['action' => 'approve'])
            ->assertSessionHas('error');

        expect(session('error'))->toContain('Tersedia 5')
            ->and($request->refresh()->status)->toBe('approved_by_head');

        // Jalan keluarnya: kurangi sesuai stok tersedia lewat Ubah Kuantitas.
        $this->put(route('request.update', $request), [
            'items' => [['item_id' => $this->kertas->item_id, 'qty' => 5]],
            'nd_sequence' => '52',
            'adjustment_note' => 'Stok tersedia tinggal 5 rim.',
        ])->assertSessionHasNoErrors();

        expect($request->refresh()->status)->toBe('processed_by_staff');
    });
});

describe('batal otomatis', function () {
    it('membatalkan permintaan terkirim yang melewati batas hari dan mengabari pemohon', function () {
        $lama = permintaanTahan($this->pemohon, 'submitted', $this->kertas, 3);
        $lama->forceFill(['created_at' => now()->subDays(ModelsRequest::BATAS_HARI_PERSETUJUAN + 1)])->save();
        $baru = permintaanTahan($this->pemohon, 'submitted', $this->kertas, 3);
        $baru->forceFill(['created_at' => now()->subDays(ModelsRequest::BATAS_HARI_PERSETUJUAN - 1)])->save();
        $diproses = permintaanTahan($this->pemohon, 'approved_by_head', $this->kertas, 3);
        $diproses->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('permintaan:kedaluwarsa')->assertSuccessful();

        $lama->refresh();
        expect($lama->status)->toBe('rejected')
            ->and($lama->rejected_from_status)->toBe('submitted')
            ->and($lama->histories()->sole()->action)->toBe(RequestHistoryAction::Kedaluwarsa)
            ->and($baru->refresh()->status)->toBe('submitted')
            ->and($diproses->refresh()->status)->toBe('approved_by_head')
            ->and(StockReservation::availableFor($this->kertas))->toBe(4);

        $notifikasi = Notification::where('user_id', $this->pemohon->user_id)
            ->where('type', NotificationType::PermintaanDitolak)
            ->first();
        expect($notifikasi?->body)->toContain('Oleh Sistem')->toContain('14 hari');

        $this->actingAs($this->pemohon)
            ->get(route('request.show', $lama))
            ->assertSee('Dibatalkan Otomatis')
            ->assertSee('Sistem');
    });

    it('menyebut tenggatnya pada tagihan Kepala Unit', function () {
        $kepala = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianHukum]);
        $request = permintaanTahan($this->pemohon, 'submitted', $this->kertas, 3);

        NotificationScanner::periksaPermintaan($request);

        expect(Notification::where('user_id', $kepala->user_id)->where('type', NotificationType::PermintaanDiajukan)->first()?->body)
            ->toContain('Batal otomatis bila belum diputuskan sampai');
    });
});
