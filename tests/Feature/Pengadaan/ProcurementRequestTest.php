<?php

use App\Enums\NotificationType;
use App\Enums\ProcurementRequestStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\ProcurementRequest;
use App\Models\User;

// Nama pembantu sengaja unik: `pengelolaGudang()` sudah dipakai
// ItemManagementTest, `pejabatPengadaan()` sudah dipakai SupplierManagementTest.
function usulanPayload(array $items, string $source = 'manual'): array
{
    return ['source' => $source, 'items' => $items];
}

/**
 * Ajukan usulan lewat alur aplikasi. Id diambil dari tujuan redirect, bukan
 * `latest('created_at')`, karena dua usulan bisa lahir pada detik yang sama.
 */
function buatUsulan(User $actor, array $items, string $source = 'manual'): ProcurementRequest
{
    $response = test()->actingAs($actor)->post('/usulan-pengadaan', usulanPayload($items, $source));

    $id = (string) str($response->headers->get('Location'))->match('#/usulan-pengadaan/([^/]+)#');

    return ProcurementRequest::findOrFail($id);
}

describe('hak akses', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/usulan-pengadaan')->assertRedirect('/login');
        $this->get('/usulan-pengadaan/create')->assertRedirect('/login');
    });

    it('mengizinkan Pengelola Gudang membuka formulir usulan', function () {
        $this->actingAs(pengelolaGudang())
            ->get('/usulan-pengadaan/create')
            ->assertOk()
            ->assertSee('Ajukan Usulan Pengadaan');
    });

    it('menolak Pejabat Pengadaan membuka formulir usulan', function () {
        $this->actingAs(pejabatPengadaan())
            ->get('/usulan-pengadaan/create')
            ->assertForbidden();
    });

    it('mengizinkan Admin membuka formulir usulan', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/usulan-pengadaan/create')
            ->assertOk();
    });

    it('mengizinkan kedua peran pengadaan membaca daftar usulan', function () {
        $this->actingAs(pengelolaGudang())->get('/usulan-pengadaan')->assertOk();
        $this->actingAs(pejabatPengadaan())->get('/usulan-pengadaan')->assertOk();
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())->get('/usulan-pengadaan')->assertOk();
    });

    it('menolak peran lain membaca daftar usulan', function () {
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->get('/usulan-pengadaan')
            ->assertForbidden();
    });

    it('menolak Pejabat Pengadaan mengajukan usulan', function () {
        $item = Item::factory()->create();

        $this->actingAs(pejabatPengadaan())
            ->post('/usulan-pengadaan', usulanPayload([['item_id' => $item->item_id, 'amount_request' => 10]]))
            ->assertForbidden();
    });

    it('menolak Pengelola Gudang menyetujui atau menolak usulan', function () {
        $usulan = buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]);

        $this->actingAs(pengelolaGudang())
            ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/setujui", ['granted' => []])
            ->assertForbidden();

        $this->actingAs(pengelolaGudang())
            ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/tolak")
            ->assertForbidden();
    });
});

describe('mengajukan usulan', function () {
    it('menyimpan usulan beserta rincian, mencatat audit, dan memberi tahu Pejabat Pengadaan', function () {
        $pengaju = pengelolaGudang();
        $pejabat = pejabatPengadaan();
        $item = Item::factory()->create(['item_name' => 'Kertas HVS A4']);

        $response = $this->actingAs($pengaju)->post('/usulan-pengadaan', usulanPayload([
            ['item_id' => $item->item_id, 'amount_request' => 50],
        ]));

        $response->assertSessionHas('status');

        $usulan = ProcurementRequest::where('user_id', $pengaju->user_id)->firstOrFail();

        expect($usulan->status)->toBe(ProcurementRequestStatus::Diajukan)
            ->and($usulan->details)->toHaveCount(1)
            ->and($usulan->details->first()->amount_request)->toBe(50)
            ->and($usulan->details->first()->amount_granted)->toBeNull();

        expect(AuditLog::where('user_id', $pengaju->user_id)
            ->where('activity', 'like', 'Mengajukan usulan pengadaan%')
            ->exists())->toBeTrue();

        expect(Notification::query()
            ->where('user_id', $pejabat->user_id)
            ->where('type', NotificationType::UsulanMenungguPersetujuan)
            ->aktif()
            ->exists())->toBeTrue();
    });

    it('menolak usulan tanpa barang', function () {
        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan', usulanPayload([]))
            ->assertSessionHasErrors('items');
    });

    it('menolak barang ganda dalam satu usulan', function () {
        $item = Item::factory()->create();

        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan', usulanPayload([
                ['item_id' => $item->item_id, 'amount_request' => 10],
                ['item_id' => $item->item_id, 'amount_request' => 5],
            ]))
            ->assertSessionHasErrors('items.1.item_id');
    });

    it('menolak jumlah diminta nol', function () {
        $item = Item::factory()->create();

        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan', usulanPayload([
                ['item_id' => $item->item_id, 'amount_request' => 0],
            ]))
            ->assertSessionHasErrors('items.0.amount_request');
    });

    it('menolak sumber dari_permintaan karena belum ada integrasinya', function () {
        $item = Item::factory()->create();

        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan', usulanPayload([
                ['item_id' => $item->item_id, 'amount_request' => 10],
            ], 'dari_permintaan'))
            ->assertSessionHasErrors('source');
    });

    it('menolak sumber pemantauan_stok karena jalur itu sekarang lewat draf otomatis', function () {
        $item = Item::factory()->create();

        $this->actingAs(pengelolaGudang())
            ->post('/usulan-pengadaan', usulanPayload([
                ['item_id' => $item->item_id, 'amount_request' => 10],
            ], 'pemantauan_stok'))
            ->assertSessionHasErrors('source');
    });
});

describe('menyetujui usulan', function () {
    it('menyetujui sebagian barang, menutup keadaan menunggu, dan memberi tahu pengaju', function () {
        $pengaju = pengelolaGudang();
        $itemA = Item::factory()->create();
        $itemB = Item::factory()->create();

        $usulan = buatUsulan($pengaju, [
            ['item_id' => $itemA->item_id, 'amount_request' => 40],
            ['item_id' => $itemB->item_id, 'amount_request' => 40],
        ]);

        $detailA = $usulan->details->firstWhere('item_id', $itemA->item_id);
        $detailB = $usulan->details->firstWhere('item_id', $itemB->item_id);

        $pejabat = pejabatPengadaan();

        $this->actingAs($pejabat)
            ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/setujui", [
                'granted' => [
                    $detailA->procurement_request_detail_id => 40,
                    $detailB->procurement_request_detail_id => 20,
                ],
            ])
            ->assertRedirect("/usulan-pengadaan/{$usulan->procurement_request_id}");

        $usulan->refresh();

        expect($usulan->status)->toBe(ProcurementRequestStatus::DisetujuiMenungguRealisasi)
            ->and($detailA->refresh()->amount_granted)->toBe(40)
            ->and($detailB->refresh()->amount_granted)->toBe(20);

        expect(Notification::query()
            ->where('user_id', $pengaju->user_id)
            ->where('type', NotificationType::UsulanDisetujui)
            ->exists())->toBeTrue();

        // Keadaan "menunggu persetujuan" untuk Pejabat Pengadaan harus tertutup.
        expect(Notification::query()
            ->where('user_id', $pejabat->user_id)
            ->where('type', NotificationType::UsulanMenungguPersetujuan)
            ->aktif()
            ->exists())->toBeFalse();
    });

    it('menolak jumlah disetujui melebihi jumlah diminta', function () {
        $item = Item::factory()->create();
        $usulan = buatUsulan(pengelolaGudang(), [['item_id' => $item->item_id, 'amount_request' => 10]]);
        $detail = $usulan->details->first();

        $this->actingAs(pejabatPengadaan())
            ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/setujui", [
                'granted' => [$detail->procurement_request_detail_id => 999],
            ])
            ->assertSessionHasErrors("granted.{$detail->procurement_request_detail_id}");

        expect($usulan->refresh()->status)->toBe(ProcurementRequestStatus::Diajukan);
    });

    it('menolak persetujuan tanpa satu pun barang disetujui', function () {
        $item = Item::factory()->create();
        $usulan = buatUsulan(pengelolaGudang(), [['item_id' => $item->item_id, 'amount_request' => 10]]);
        $detail = $usulan->details->first();

        $this->actingAs(pejabatPengadaan())
            ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/setujui", [
                'granted' => [$detail->procurement_request_detail_id => 0],
            ])
            ->assertSessionHasErrors('granted');
    });

    it('menolak menyetujui usulan yang sudah diputuskan', function () {
        $item = Item::factory()->create();
        $usulan = buatUsulan(pengelolaGudang(), [['item_id' => $item->item_id, 'amount_request' => 10]]);
        $detail = $usulan->details->first();

        $usulan->forceFill(['status' => ProcurementRequestStatus::Ditolak])->save();

        $this->actingAs(pejabatPengadaan())
            ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/setujui", [
                'granted' => [$detail->procurement_request_detail_id => 10],
            ])
            ->assertSessionHas('error');

        expect($usulan->refresh()->status)->toBe(ProcurementRequestStatus::Ditolak);
    });
});

describe('menolak usulan', function () {
    it('menolak usulan, mengisi amount_granted nol pada seluruh barang, dan memberi tahu pengaju', function () {
        $pengaju = pengelolaGudang();
        $item = Item::factory()->create();
        $usulan = buatUsulan($pengaju, [['item_id' => $item->item_id, 'amount_request' => 10]]);

        $this->actingAs(pejabatPengadaan())
            ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/tolak")
            ->assertRedirect("/usulan-pengadaan/{$usulan->procurement_request_id}");

        $usulan->refresh();

        expect($usulan->status)->toBe(ProcurementRequestStatus::Ditolak)
            ->and($usulan->details->first()->amount_granted)->toBe(0);

        expect(Notification::query()
            ->where('user_id', $pengaju->user_id)
            ->where('type', NotificationType::UsulanDitolak)
            ->exists())->toBeTrue();
    });

    it('menolak menolak usulan yang sudah diputuskan', function () {
        $item = Item::factory()->create();
        $usulan = buatUsulan(pengelolaGudang(), [['item_id' => $item->item_id, 'amount_request' => 10]]);
        $usulan->forceFill(['status' => ProcurementRequestStatus::DisetujuiMenungguRealisasi])->save();

        $this->actingAs(pejabatPengadaan())
            ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/tolak")
            ->assertSessionHas('error');

        expect($usulan->refresh()->status)->toBe(ProcurementRequestStatus::DisetujuiMenungguRealisasi);
    });
});

describe('jalur pintas dari pemantauan stok', function () {
    it('menampilkan tombol menuju draf pada barang yang stoknya habis', function () {
        $item = Item::factory()->habis()->create();

        $this->actingAs(pengelolaGudang())
            ->get("/barang/{$item->item_id}")
            ->assertOk()
            ->assertSee('Lihat Draf Usulan Pengadaan')
            ->assertSee('/usulan-pengadaan/draf', false);
    });

    it('menyembunyikan tombol usulan pengadaan pada barang yang stoknya aman', function () {
        $item = Item::factory()->create(['stock_current' => 100, 'stock_min' => 10, 'stock_max' => 0]);

        $this->actingAs(pengelolaGudang())
            ->get("/barang/{$item->item_id}")
            ->assertOk()
            ->assertDontSee('Lihat Draf Usulan Pengadaan');
    });
});
