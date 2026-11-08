<?php

use App\Enums\AuditModule;
use App\Enums\ItemApplicationStatus;
use App\Enums\ItemProposalStatus;
use App\Enums\NotificationType;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Enums\SatuanBarang;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemApplication;
use App\Models\ItemProposal;
use App\Models\Notification;
use App\Models\ProcurementRequest;
use App\Models\ProcurementRequestDetail;
use App\Models\Request as ModelsRequest;
use App\Models\User;
use App\Support\RequestDocumentGenerator;

/*
 * Permohonan barang baru dan tambah stok. Helper Pest bersifat global lintas
 * berkas, jadi namanya bersufiks `Permohonan`.
 */

function barisTambahStokPermohonan(Item $item, int $quantity, ?string $note = null): array
{
    return ['type' => 'tambah_stok', 'item_id' => $item->item_id, 'quantity' => $quantity, 'note' => $note];
}

function barisBarangBaruPermohonan(string $name = 'Spidol Permanen Emas', int $quantity = 5): array
{
    return [
        'type' => 'barang_baru',
        'item_name' => $name,
        'unit' => SatuanBarang::Buah->value,
        'note' => 'Untuk menandai berkas pemeriksaan; yang ada hanya hitam dan merah.',
        'quantity' => $quantity,
    ];
}

function ajukanPermohonan(User $pemohon, array $items): ItemApplication
{
    test()->actingAs($pemohon)
        ->post(route('item-applications.store'), ['purpose' => 'Kebutuhan kegiatan triwulan IV', 'items' => $items])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    return ItemApplication::where('user_id', $pemohon->user_id)->latest('created_at')->firstOrFail();
}

beforeEach(function () {
    $this->pemohon = User::factory()->role(UserRole::Pengguna)->create();
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $this->item = Item::factory()->create(['stock_current' => 3]);
});

describe('mengajukan permohonan', function () {
    it('menyimpan permohonan tanpa langsung meneruskannya ke pengadaan', function () {
        $application = ajukanPermohonan($this->pemohon, [
            barisTambahStokPermohonan($this->item, 20),
            barisBarangBaruPermohonan(),
        ]);

        expect($application->status)->toBe(ItemApplicationStatus::Diajukan)
            ->and($application->details)->toHaveCount(2)
            ->and(ProcurementRequest::count())->toBe(0)
            ->and(ItemProposal::count())->toBe(0);

        $notifikasi = Notification::where('user_id', $this->gudang->user_id)
            ->where('type', NotificationType::PermohonanMenungguTinjauan)
            ->first();

        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('1 tambah stok, 1 barang baru')
            ->and(AuditLog::where('module', AuditModule::Permohonan)->exists())->toBeTrue();
    });

    it('mewajibkan keperluan serta nama, satuan, dan kegunaan barang baru', function () {
        $this->actingAs($this->pemohon)
            ->post(route('item-applications.store'), [
                'purpose' => '',
                'items' => [['type' => 'barang_baru', 'item_name' => '', 'unit' => '', 'note' => '', 'quantity' => 1]],
            ])
            ->assertSessionHasErrors(['purpose', 'items.0.item_name', 'items.0.unit', 'items.0.note']);

        expect(ItemApplication::count())->toBe(0);
    });

    it('menolak tambah stok tanpa barang atau untuk barang yang diarsipkan', function () {
        $arsip = Item::factory()->arsip()->create();

        $this->actingAs($this->pemohon)
            ->post(route('item-applications.store'), [
                'purpose' => 'Kebutuhan rutin',
                'items' => [
                    ['type' => 'tambah_stok', 'item_id' => '', 'quantity' => 1],
                    barisTambahStokPermohonan($arsip, 1),
                ],
            ])
            ->assertSessionHasErrors(['items.0.item_id', 'items.1.item_id']);
    });
});

describe('melihat permohonan', function () {
    it('membatasi pengguna pada permohonannya sendiri', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);
        $lain = User::factory()->role(UserRole::Pengguna)->create();

        $this->actingAs($lain)->get(route('item-applications.show', $application))->assertForbidden();
        $this->actingAs($lain)->get(route('item-applications.index'))->assertDontSee($application->application_number);

        $this->actingAs($this->pemohon)->get(route('item-applications.show', $application))->assertOk();
        $this->actingAs($this->gudang)->get(route('item-applications.index'))->assertSee($application->application_number);
    });

    it('merender formulir pengajuan', function () {
        $this->actingAs($this->pemohon)->get(route('item-applications.create'))->assertOk();
    });
});

describe('menyetujui permohonan', function () {
    it('hanya boleh dilakukan Pengelola Gudang atau Admin', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);

        $this->actingAs($this->pemohon)
            ->patch(route('item-applications.approve', $application))
            ->assertForbidden();

        expect($application->fresh()->status)->toBe(ItemApplicationStatus::Diajukan);
    });

    it('meneruskan tambah stok ke draf usulan dan barang baru ke usulan barang', function () {
        $application = ajukanPermohonan($this->pemohon, [
            barisTambahStokPermohonan($this->item, 20),
            barisBarangBaruPermohonan(),
        ]);

        $this->actingAs($this->gudang)
            ->patch(route('item-applications.approve', $application))
            ->assertRedirect(route('item-applications.show', $application));

        $application->refresh()->load('details');
        $draft = ProcurementRequest::query()->draf()->sole();
        $line = $draft->details()->where('item_id', $this->item->item_id)->sole();
        $proposal = ItemProposal::sole();

        expect($application->status)->toBe(ItemApplicationStatus::Disetujui)
            ->and($application->decided_by)->toBe($this->gudang->user_id)
            ->and($line->source)->toBe(ProcurementRequestSource::DariPermohonan)
            ->and($line->amount_request)->toBe(20)
            ->and($line->is_auto)->toBeFalse()
            ->and($proposal->status)->toBe(ItemProposalStatus::Diajukan)
            ->and($proposal->proposed_by)->toBe($this->pemohon->user_id)
            ->and($proposal->item_name)->toBe('Spidol Permanen Emas');

        $tambahStok = $application->details->firstWhere('type.value', 'tambah_stok');
        $barangBaru = $application->details->firstWhere('type.value', 'barang_baru');

        expect($tambahStok->procurement_request_id)->toBe($draft->procurement_request_id)
            ->and($barangBaru->item_proposal_id)->toBe($proposal->item_proposal_id);

        // Tagihan tinjauan tertutup, pemohon diberi tahu.
        expect(Notification::where('type', NotificationType::PermohonanMenungguTinjauan)->exists())->toBeFalse()
            ->and(Notification::where('user_id', $this->pemohon->user_id)
                ->where('type', NotificationType::PermohonanDisetujui)->exists())->toBeTrue();

        $this->actingAs($this->pemohon)->get(route('item-applications.show', $application))->assertOk();
    });

    it('menandai baris draf dan usulan yang berasal dari permohonan', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 6)]);
        $this->actingAs($this->gudang)->patch(route('item-applications.approve', $application));

        $this->get(route('procurement-requests.draft.edit'))
            ->assertOk()
            ->assertSee('Permohonan Pengguna')
            ->assertSee($application->application_number)
            ->assertSee($this->pemohon->fullname);

        $this->post(route('procurement-requests.draft.finalize'), ['draft_id' => ProcurementRequest::query()->draf()->value('procurement_request_id')]);
        $usulan = ProcurementRequest::query()->bukanDraf()->sole();

        expect($usulan->details()->sole()->source)->toBe(ProcurementRequestSource::DariPermohonan);

        $this->get(route('procurement-requests.show', $usulan))
            ->assertOk()
            ->assertSee('Permohonan Pengguna')
            ->assertSee($application->application_number);
    });

    it('menjumlahkan ke baris draf yang sudah ada untuk barang yang sama', function () {
        $draft = ProcurementRequest::create([
            'user_id' => null,
            'status' => ProcurementRequestStatus::Draf,
        ]);
        $draft->details()->create([
            'item_id' => $this->item->item_id,
            'source' => ProcurementRequestSource::PemantauanStok,
            'amount_request' => 7,
            'is_auto' => true,
        ]);

        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);
        $this->actingAs($this->gudang)->patch(route('item-applications.approve', $application));

        $line = ProcurementRequestDetail::where('item_id', $this->item->item_id)->sole();

        expect(ProcurementRequest::count())->toBe(1)
            ->and($line->amount_request)->toBe(12)
            ->and($line->is_auto)->toBeFalse()
            ->and($line->source)->toBe(ProcurementRequestSource::DariPermohonan);
    });

    it('tidak dapat diputuskan dua kali', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);

        $this->actingAs($this->gudang)->patch(route('item-applications.approve', $application));
        $this->actingAs($this->gudang)
            ->patch(route('item-applications.approve', $application))
            ->assertSessionHas('error');

        expect(ProcurementRequestDetail::sole()->amount_request)->toBe(5);
    });
});

describe('menolak permohonan', function () {
    it('mewajibkan alasan', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);

        $this->actingAs($this->gudang)
            ->patch(route('item-applications.reject', $application), ['decision_note' => ''])
            ->assertSessionHasErrors('decision_note');

        expect($application->fresh()->status)->toBe(ItemApplicationStatus::Diajukan);
    });

    it('menutup permohonan tanpa meneruskan apa pun', function () {
        $application = ajukanPermohonan($this->pemohon, [
            barisTambahStokPermohonan($this->item, 5),
            barisBarangBaruPermohonan(),
        ]);

        $this->actingAs($this->gudang)
            ->patch(route('item-applications.reject', $application), ['decision_note' => 'Stok masih cukup sampai akhir tahun.'])
            ->assertRedirect(route('item-applications.show', $application));

        $application->refresh();

        expect($application->status)->toBe(ItemApplicationStatus::Ditolak)
            ->and($application->decision_note)->toBe('Stok masih cukup sampai akhir tahun.')
            ->and(ProcurementRequest::count())->toBe(0)
            ->and(ItemProposal::count())->toBe(0);

        $notifikasi = Notification::where('user_id', $this->pemohon->user_id)
            ->where('type', NotificationType::PermohonanDitolak)
            ->first();

        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('Stok masih cukup sampai akhir tahun.');
    });
});

describe('permintaan barang', function () {
    beforeEach(function () {
        test()->mock(RequestDocumentGenerator::class)->shouldReceive('generateNotaDinas');
    });

    it('tidak lagi menghitung selisih stok menjadi draf usulan pengadaan', function () {
        $this->actingAs($this->pemohon)
            ->post(route('request.store'), [
                'nd_sequence' => '51',
                'items' => [['item_id' => $this->item->item_id, 'qty' => 3]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('request.index'));

        expect(ModelsRequest::count())->toBe(1)
            ->and(ProcurementRequest::count())->toBe(0);
    });

    it('menolak jumlah yang melebihi stok, termasuk gabungan baris barang yang sama', function () {
        $this->actingAs($this->pemohon)
            ->post(route('request.store'), ['nd_sequence' => '51', 'items' => [
                ['item_id' => $this->item->item_id, 'qty' => 2],
                ['item_id' => $this->item->item_id, 'qty' => 2],
            ]])
            ->assertSessionHasErrors('items.0.qty');

        expect(ModelsRequest::count())->toBe(0)
            ->and(ProcurementRequest::count())->toBe(0);
    });

    it('tidak lagi menerima barang yang belum terdaftar', function () {
        $this->actingAs($this->pemohon)
            ->post(route('request.store'), ['nd_sequence' => '51', 'items' => [[
                'source' => 'proposal',
                'item_name' => 'Barang Baru',
                'unit' => SatuanBarang::Buah->value,
                'purpose_note' => 'Kebutuhan baru',
                'qty' => 1,
            ]]])
            ->assertSessionHasErrors('items');

        expect(ModelsRequest::count())->toBe(0)
            ->and(ItemProposal::count())->toBe(0);
    });

    it('merender formulir permintaan tanpa mode barang belum terdaftar', function () {
        $this->actingAs($this->pemohon)
            ->get(route('request.create'))
            ->assertOk()
            ->assertDontSee('Input barang belum terdaftar')
            ->assertSee(route('item-applications.create'));
    });
});
