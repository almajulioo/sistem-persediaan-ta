<?php

use App\Enums\NotificationType;
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
 * Peran Kepala Unit disebut menurut unit pemohon: Kepala Subbagian, Kepala
 * Bidang Pemeriksaan, Kepala Perwakilan, atau Kepala Sekretariat. Nilai peran
 * di basis data tidak berubah. Helper bersufiks `Sebutan`.
 */

function permintaanSebutan(UnitKerja $unit, string $status = 'approved_by_head'): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => User::factory()->role(UserRole::Pengguna)->create(['unit' => $unit])->user_id,
        'date' => now(),
        'type' => 'normal',
        'status' => $status,
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
    ]);

    RequestDetail::create([
        'request_id' => $request->request_id,
        'item_id' => Item::factory()->create(['stock_current' => 10])->item_id,
        'quantity_requested' => 2,
    ]);

    return $request;
}

it('menyebut penyetuju menurut unit pemohon', function (UnitKerja $unit, string $label) {
    expect(permintaanSebutan($unit)->statusLabel())->toBe($label);
})->with([
    'subbagian' => [UnitKerja::SubbagianHukum, 'Disetujui Kepala Subbagian'],
    'subbagian umum dan TI' => [UnitKerja::SubbagianUmumTi, 'Disetujui Kepala Subbagian'],
    'bidang pemeriksaan' => [UnitKerja::BidangPemeriksaanLampungI, 'Disetujui Kepala Bidang Pemeriksaan'],
    'kepala perwakilan' => [UnitKerja::KepalaPerwakilan, 'Disetujui Kepala Perwakilan'],
    'sekretariat' => [UnitKerja::SekretariatPerwakilan, 'Disetujui Kepala Sekretariat'],
]);

it('memakai label umum di tempat yang tidak terikat satu permintaan', function () {
    expect(ModelsRequest::labelStatus('approved_by_head'))->toBe('Disetujui Kepala Subbagian/Bidang')
        ->and(NotificationType::PermintaanDisetujuiKepalaUnit->label())->toBe('Permintaan Disetujui Kepala Subbagian/Bidang');
});

it('menyebut jabatan Kepala Unit menurut unitnya, peran lain tetap', function () {
    expect(User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::BidangPemeriksaanLampungII])->sebutanJabatan())
        ->toBe('Kepala Bidang Pemeriksaan')
        ->and(User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianSdm])->sebutanJabatan())
        ->toBe('Kepala Subbagian')
        ->and(User::factory()->role(UserRole::PengelolaGudang)->create()->sebutanJabatan())
        ->toBe(UserRole::PengelolaGudang->label());
});

it('menampilkan sebutan yang tepat di daftar, riwayat, dan notifikasi Gudang', function () {
    $this->mock(RequestDocumentGenerator::class)->shouldIgnoreMissing();
    $kabid = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::BidangPemeriksaanLampungI]);
    $gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $request = permintaanSebutan(UnitKerja::BidangPemeriksaanLampungI, 'submitted');

    $this->actingAs($kabid)
        ->post(route('request.respond', $request), ['action' => 'approve'])
        ->assertSessionHasNoErrors();

    $this->get(route('request.index'))->assertSee('Disetujui Kepala Bidang Pemeriksaan');
    $this->get(route('request.show', $request))
        ->assertSee('Disetujui Kepala Bidang Pemeriksaan')
        ->assertSeeInOrder([$kabid->fullname, '· Kepala Bidang Pemeriksaan'])
        ->assertDontSee('Kepala Unit');

    expect(Notification::where('user_id', $gudang->user_id)
        ->where('type', NotificationType::PermintaanDisetujuiKepalaUnit)
        ->first()?->title)->toContain('Disetujui Kepala Bidang Pemeriksaan');
});

it('mencetak jabatan pimpinan unit pemohon di Nota Dinas walau disetujui Admin', function () {
    $request = permintaanSebutan(UnitKerja::BidangPemeriksaanLampungII)->load(['user', 'item.itemProposal']);
    $admin = User::factory()->role(UserRole::Admin)->create(['unit' => UnitKerja::SekretariatPerwakilan]);

    $html = view('pdf.notadinas-permintaan', [
        'request' => $request,
        'approvedBy' => $admin,
        'approvedAt' => now(),
        'qrCodeDataUri' => null,
        'kepalaUnit' => null,
    ])->render();

    expect($html)->toContain('Kepala Bidang Pemeriksaan Lampung II,')
        ->not->toContain('Kepala Sekretariat Perwakilan,');
});

it('menampilkan sebutan jabatan Kepala Unit di dasbor', function () {
    $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianKeuangan]))
        ->get('/dasbor')
        ->assertOk()
        ->assertSee('Kepala Subbagian');
});

it('menagih Kepala Bidang Pemeriksaan saat permintaan bidangnya diajukan', function () {
    $kabid = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::BidangPemeriksaanLampungI]);
    $request = permintaanSebutan(UnitKerja::BidangPemeriksaanLampungI, 'submitted');

    NotificationScanner::periksaPermintaan($request);

    expect(Notification::where('user_id', $kabid->user_id)->where('type', NotificationType::PermintaanDiajukan)->exists())->toBeTrue();
});
