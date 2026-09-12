<?php

use App\Enums\NotificationType;
use App\Enums\ProcurementRequestStatus;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Procurement;
use App\Models\ProcurementRequest;
use App\Models\Supplier;
use App\Models\User;

/**
 * Setujui seluruh rincian sebuah usulan lewat alur aplikasi, mengembalikan
 * usulan yang sudah Disetujui.
 */
function setujuiUsulan(User $pejabat, ProcurementRequest $usulan): ProcurementRequest
{
    $granted = $usulan->details->mapWithKeys(
        fn ($detail) => [$detail->procurement_request_detail_id => $detail->amount_request],
    )->all();

    test()->actingAs($pejabat)
        ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/setujui", ['granted' => $granted]);

    return $usulan->refresh();
}

/**
 * @return array<string, mixed>
 */
function realisasiPayload(ProcurementRequest $usulan, Supplier $supplier, array $overrides = []): array
{
    $items = $usulan->details->map(fn ($detail) => [
        'procurement_request_detail_id' => $detail->procurement_request_detail_id,
        'amount' => $detail->amount_granted,
        'unit_price' => 10000,
    ])->all();

    return array_merge([
        'procurement_request_id' => $usulan->procurement_request_id,
        'supplier_id' => $supplier->supplier_id,
        'procurement_date' => now()->toDateString(),
        'items' => $items,
    ], $overrides);
}

/**
 * Buat realisasi lewat alur aplikasi, mengembalikan model Procurement.
 */
function buatRealisasi(User $pejabat, ProcurementRequest $usulan, Supplier $supplier, array $overrides = []): Procurement
{
    $response = test()->actingAs($pejabat)->post('/pengadaan', realisasiPayload($usulan, $supplier, $overrides));

    $id = (string) str($response->headers->get('Location'))->match('#/pengadaan/([^/]+)#');

    return Procurement::findOrFail($id);
}

describe('hak akses realisasi', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/pengadaan')->assertRedirect('/login');
    });

    it('mengizinkan kedua peran pengadaan membaca daftar dan detail realisasi', function () {
        $usulan = setujuiUsulan(pejabatPengadaan(), buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        $procurement = buatRealisasi(pejabatPengadaan(), $usulan, Supplier::factory()->create());

        $this->actingAs(pengelolaGudang())->get('/pengadaan')->assertOk();
        $this->actingAs(pejabatPengadaan())->get("/pengadaan/{$procurement->procurement_id}")->assertOk();
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())->get('/pengadaan')->assertOk();
    });

    it('menolak peran lain membaca realisasi', function () {
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->get('/pengadaan')
            ->assertForbidden();
    });

    it('mengizinkan Pejabat Pengadaan membuka formulir realisasi', function () {
        $usulan = setujuiUsulan(pejabatPengadaan(), buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));

        $this->actingAs(pejabatPengadaan())
            ->get("/pengadaan/create?procurement_request_id={$usulan->procurement_request_id}")
            ->assertOk()
            ->assertSee('Realisasi Pengadaan');
    });

    it('menolak Pengelola Gudang membuka formulir realisasi', function () {
        $usulan = setujuiUsulan(pejabatPengadaan(), buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));

        $this->actingAs(pengelolaGudang())
            ->get("/pengadaan/create?procurement_request_id={$usulan->procurement_request_id}")
            ->assertForbidden();
    });
});

describe('membuat realisasi', function () {
    it('menyimpan realisasi beserta rincian, mencatat audit, dan memberi tahu Pengelola Gudang', function () {
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $item = Item::factory()->create(['item_name' => 'Tinta Printer Hitam']);
        $supplier = Supplier::factory()->create();

        $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
            ['item_id' => $item->item_id, 'amount_request' => 40],
        ]));

        $procurement = buatRealisasi($pejabat, $usulan, $supplier);

        expect($procurement->status)->toBe(ProcurementStatus::MenungguPenerimaan)
            ->and($procurement->user_id)->toBe($pejabat->user_id)
            ->and($procurement->details)->toHaveCount(1)
            ->and($procurement->details->first()->amount)->toBe(40)
            ->and($procurement->details->first()->unit_price)->toBe(10000)
            ->and($procurement->details->first()->amount_received)->toBeNull();

        expect(AuditLog::where('user_id', $pejabat->user_id)
            ->where('activity', 'like', 'Membuat realisasi pengadaan%')
            ->exists())->toBeTrue();

        expect(Notification::query()
            ->where('user_id', $gudang->user_id)
            ->where('type', NotificationType::RealisasiMenungguPenerimaan)
            ->aktif()
            ->exists())->toBeTrue();
    });

    it('memindahkan status usulan asalnya dari menunggu realisasi menjadi selesai', function () {
        $pejabat = pejabatPengadaan();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        expect($usulan->status)->toBe(ProcurementRequestStatus::DisetujuiMenungguRealisasi);

        buatRealisasi($pejabat, $usulan, Supplier::factory()->create());

        expect($usulan->fresh()->status)->toBe(ProcurementRequestStatus::DisetujuiSelesai);
    });

    it('menolak realisasi dari usulan yang belum disetujui', function () {
        $item = Item::factory()->create();
        $usulan = buatUsulan(pengelolaGudang(), [['item_id' => $item->item_id, 'amount_request' => 10]]);

        $this->actingAs(pejabatPengadaan())
            ->post('/pengadaan', realisasiPayload($usulan, Supplier::factory()->create()))
            ->assertSessionHasErrors('procurement_request_id');

        expect(Procurement::count())->toBe(0);
    });

    it('menolak realisasi kedua dari usulan yang sudah punya realisasi', function () {
        $pejabat = pejabatPengadaan();
        $item = Item::factory()->create();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        $supplier = Supplier::factory()->create();

        buatRealisasi($pejabat, $usulan, $supplier);

        $this->actingAs($pejabat)
            ->post('/pengadaan', realisasiPayload($usulan->refresh(), $supplier))
            ->assertSessionHasErrors('procurement_request_id');

        expect(Procurement::count())->toBe(1);
    });

    it('menolak jumlah pesan melebihi jumlah disetujui', function () {
        $pejabat = pejabatPengadaan();
        $item = Item::factory()->create();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        $detail = $usulan->details->first();

        $this->actingAs($pejabat)
            ->post('/pengadaan', realisasiPayload($usulan, Supplier::factory()->create(), [
                'items' => [[
                    'procurement_request_detail_id' => $detail->procurement_request_detail_id,
                    'amount' => 999,
                    'unit_price' => 5000,
                ]],
            ]))
            ->assertSessionHasErrors('items.0.amount');
    });

    it('mengarahkan kembali dengan galat bila usulan pada tautan tidak ditemukan', function () {
        $this->actingAs(pejabatPengadaan())
            ->get('/pengadaan/create?procurement_request_id=tidak-ada')
            ->assertRedirect('/usulan-pengadaan')
            ->assertSessionHas('error');
    });
});

describe('jalur dari halaman usulan', function () {
    it('menampilkan tombol Realisasikan pada usulan yang disetujui dan belum direalisasikan', function () {
        $pejabat = pejabatPengadaan();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));

        $this->actingAs($pejabat)
            ->get("/usulan-pengadaan/{$usulan->procurement_request_id}")
            ->assertOk()
            ->assertSee('Realisasikan');
    });

    it('menampilkan tautan Lihat Realisasi begitu usulan sudah direalisasikan', function () {
        $pejabat = pejabatPengadaan();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        buatRealisasi($pejabat, $usulan, Supplier::factory()->create());

        $this->actingAs($pejabat)
            ->get("/usulan-pengadaan/{$usulan->procurement_request_id}")
            ->assertOk()
            ->assertSee('Lihat Realisasi')
            ->assertDontSee('Realisasikan');
    });
});

describe('konfirmasi penerimaan', function () {
    it('menambah stok sebanyak jumlah diterima, bukan jumlah dipesan, dan mengonfirmasi lewat Pengelola Gudang', function () {
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $item = Item::factory()->create(['stock_current' => 5]);
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 40],
        ]));
        $procurement = buatRealisasi($pejabat, $usulan, Supplier::factory()->create());
        $detail = $procurement->details->first();

        $response = $this->actingAs($gudang)->post("/pengadaan/{$procurement->procurement_id}/penerimaan", [
            'bast_number' => '001/BAST/2026',
            'received_at' => now()->toDateString(),
            'received' => [$detail->procurement_detail_id => 30],
        ]);

        $response->assertRedirect("/pengadaan/{$procurement->procurement_id}");

        expect($item->refresh()->stock_current)->toBe(35)
            ->and($detail->refresh()->amount_received)->toBe(30)
            ->and($detail->kurangKirim())->toBe(10)
            ->and($procurement->refresh()->status)->toBe(ProcurementStatus::Diterima)
            ->and($procurement->receipt->bast_number)->toBe('001/BAST/2026')
            ->and($procurement->receipt->received_by)->toBe($gudang->user_id);

        expect(Notification::query()
            ->where('user_id', $pejabat->user_id)
            ->where('type', NotificationType::PenerimaanDikonfirmasi)
            ->exists())->toBeTrue();

        // Keadaan "menunggu penerimaan" untuk Pengelola Gudang harus tertutup.
        expect(Notification::query()
            ->where('user_id', $gudang->user_id)
            ->where('type', NotificationType::RealisasiMenungguPenerimaan)
            ->aktif()
            ->exists())->toBeFalse();
    });

    it('menolak Pejabat Pengadaan mengonfirmasi penerimaan', function () {
        $pejabat = pejabatPengadaan();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        $procurement = buatRealisasi($pejabat, $usulan, Supplier::factory()->create());

        $this->actingAs($pejabat)
            ->get("/pengadaan/{$procurement->procurement_id}/penerimaan/create")
            ->assertForbidden();
    });

    it('menolak konfirmasi ulang pada realisasi yang sudah diterima', function () {
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $item = Item::factory()->create(['stock_current' => 0]);
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        $procurement = buatRealisasi($pejabat, $usulan, Supplier::factory()->create());
        $detail = $procurement->details->first();

        $this->actingAs($gudang)->post("/pengadaan/{$procurement->procurement_id}/penerimaan", [
            'bast_number' => '001/BAST/2026',
            'received_at' => now()->toDateString(),
            'received' => [$detail->procurement_detail_id => 10],
        ]);

        $this->actingAs($gudang)
            ->get("/pengadaan/{$procurement->procurement_id}/penerimaan/create")
            ->assertRedirect("/pengadaan/{$procurement->procurement_id}")
            ->assertSessionHas('error');

        expect($detail->item->refresh()->stock_current)->toBe(10);
    });

    it('menolak konfirmasi yang tidak mencakup seluruh baris barang', function () {
        // Penerimaan adalah peristiwa sekali jadi tanpa rute ubah, jadi baris
        // yang hilang dari kiriman akan terbaca "nol diterima" secara permanen.
        // Aturannya sama dengan formulir realisasi pada ADR 0021.
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $kertas = Item::factory()->create(['item_name' => 'Kertas HVS A4', 'stock_current' => 0]);
        $tinta = Item::factory()->create(['item_name' => 'Tinta Printer', 'stock_current' => 0]);

        $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
            ['item_id' => $kertas->item_id, 'amount_request' => 10],
            ['item_id' => $tinta->item_id, 'amount_request' => 5],
        ]));
        $procurement = buatRealisasi($pejabat, $usulan, Supplier::factory()->create());

        $this->actingAs($gudang)
            ->post("/pengadaan/{$procurement->procurement_id}/penerimaan", [
                'bast_number' => '009/BAST/2026',
                'received_at' => now()->toDateString(),
                // Hanya satu dari dua barang yang diisi.
                'received' => [$procurement->details->first()->procurement_detail_id => 10],
            ])
            ->assertSessionHasErrors('received');

        expect($procurement->fresh()->isMenungguPenerimaan())->toBeTrue()
            ->and($kertas->refresh()->stock_current)->toBe(0)
            ->and($tinta->refresh()->stock_current)->toBe(0);
    });
});

describe('saran nomor BAST', function () {
    it('menomori berurut dalam tahun yang sama', function () {
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();

        // Realisasinya dibangun lebih dulu: pembantunya sendiri memakai
        // actingAs($pejabat), sehingga membangunnya di dalam rantai
        // actingAs($gudang)->get(...) justru menukar penggunanya dan menghasilkan 403.
        $pertama = realisasiMenunggu($pejabat, $gudang);

        $this->actingAs($gudang)
            ->get("/pengadaan/{$pertama->procurement_id}/penerimaan/create")
            ->assertOk()
            ->assertSee('001/BAST/'.now()->year);

        realisasiDiterima($pejabat, $gudang);
        $kedua = realisasiMenunggu($pejabat, $gudang);

        $this->actingAs($gudang)
            ->get("/pengadaan/{$kedua->procurement_id}/penerimaan/create")
            ->assertOk()
            ->assertSee('002/BAST/'.now()->year);
    });

    it('mengulang dari satu pada tahun berikutnya', function () {
        // Penomoran dokumen resmi dimulai ulang tiap tahun; saran yang keliru
        // berbahaya justru karena petugas mempercayainya.
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        realisasiDiterima($pejabat, $gudang);

        $this->travel(1)->years();
        $berikutnya = realisasiMenunggu($pejabat, $gudang);

        $this->actingAs($gudang)
            ->get("/pengadaan/{$berikutnya->procurement_id}/penerimaan/create")
            ->assertOk()
            ->assertSee('001/BAST/'.now()->year);
    });
});
