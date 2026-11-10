<?php

use App\Enums\AuditModule;
use App\Enums\ItemApplicationStatus;
use App\Enums\ItemProposalStatus;
use App\Enums\NotificationType;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Enums\SatuanBarang;
use App\Enums\UnitKerja;
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
use App\Services\RequestWorkflowService;
use App\Support\ItemApplicationDocumentGenerator;
use App\Support\RequestDocumentGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    static $nomorUrut = 100;

    $nomorUrut++;

    test()->actingAs($pemohon)
        ->post(route('item-applications.store'), [
            'nd_sequence' => (string) $nomorUrut,
            'purpose' => 'Kebutuhan kegiatan triwulan IV',
            'items' => $items,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    return ItemApplication::where('nd_number', 'like', "{$nomorUrut}/%")->firstOrFail();
}

/**
 * @return array{xml: string, qr: list<string>}
 */
function isiNotaDinasPermohonan(ItemApplication $application): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($application->fresh()->notaDinasPath()));

    $qr = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with($zip->getNameIndex($i), 'word/media/qr-')) {
            $qr[] = basename($zip->getNameIndex($i));
        }
    }

    $isi = ['xml' => $zip->getFromName('word/document.xml'), 'qr' => $qr];
    $zip->close();

    return $isi;
}

/**
 * Setujui berurutan, satu tahap per akun yang disebut.
 */
function setujuiPermohonan(ItemApplication $application, User ...$penyetuju): void
{
    foreach ($penyetuju as $user) {
        test()->actingAs($user)
            ->patch(route('item-applications.approve', $application))
            ->assertSessionMissing('error');
    }
}

beforeEach(function () {
    // Pembuatan PDF ditiru kecuali di bagian Nota Dinas supaya uji alur tidak
    // merender dompdf berulang kali.
    $this->mock(ItemApplicationDocumentGenerator::class)->shouldIgnoreMissing();

    $this->pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum]);
    $this->kepala = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianHukum]);
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create(['unit' => UnitKerja::SubbagianUmumTi]);
    $this->kasubbag = User::factory()->role(UserRole::KasubbagUmumTi)->create(['unit' => UnitKerja::SubbagianUmumTi]);
    $this->item = Item::factory()->create(['stock_current' => 3]);
});

describe('mengajukan permohonan', function () {
    it('menyimpan permohonan dan menagih pimpinan unit, bukan langsung meneruskannya', function () {
        $application = ajukanPermohonan($this->pemohon, [
            barisTambahStokPermohonan($this->item, 20),
            barisBarangBaruPermohonan(),
        ]);

        expect($application->status)->toBe(ItemApplicationStatus::Diajukan)
            ->and($application->details)->toHaveCount(2)
            ->and(ProcurementRequest::count())->toBe(0)
            ->and(ItemProposal::count())->toBe(0);

        $notifikasi = Notification::where('user_id', $this->kepala->user_id)
            ->where('type', NotificationType::PermohonanMenungguKepalaUnit)
            ->first();

        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('1 tambah stok, 1 barang baru')
            ->and(Notification::where('user_id', $this->gudang->user_id)->exists())->toBeFalse()
            ->and(AuditLog::where('module', AuditModule::Permohonan)->exists())->toBeTrue();
    });

    it('mewajibkan keperluan serta nama, satuan, dan kegunaan barang baru', function () {
        $this->actingAs($this->pemohon)
            ->post(route('item-applications.store'), [
                'nd_sequence' => '7',
                'purpose' => '',
                'items' => [['type' => 'barang_baru', 'item_name' => '', 'unit' => '', 'note' => '', 'quantity' => 1]],
            ])
            ->assertSessionHasErrors(['purpose', 'items.0.item_name', 'items.0.unit', 'items.0.note']);

        expect(ItemApplication::count())->toBe(0);
    });

    it('mewajibkan nomor urut Nota Dinas berupa angka', function () {
        foreach (['', 'abc', '1234567'] as $nomorUrut) {
            $this->actingAs($this->pemohon)
                ->post(route('item-applications.store'), [
                    'nd_sequence' => $nomorUrut,
                    'purpose' => 'Kebutuhan rutin',
                    'items' => [barisTambahStokPermohonan($this->item, 1)],
                ])
                ->assertSessionHasErrors('nd_sequence');
        }

        expect(ItemApplication::count())->toBe(0);
    });

    it('menolak tambah stok tanpa barang atau untuk barang yang diarsipkan', function () {
        $arsip = Item::factory()->arsip()->create();

        $this->actingAs($this->pemohon)
            ->post(route('item-applications.store'), [
                'nd_sequence' => '7',
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

    it('menampilkan tombol keputusan hanya kepada yang gilirannya, di setiap tahap', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);

        foreach ([$this->kepala, $this->gudang, $this->kasubbag] as $giliran) {
            $this->actingAs($this->pemohon)
                ->get(route('item-applications.show', $application))
                ->assertOk()
                ->assertSee('Tahapan Persetujuan')
                ->assertDontSee('Setujui &amp; Teruskan', false);

            $this->actingAs($giliran)
                ->get(route('item-applications.show', $application))
                ->assertOk()
                ->assertSee('Setujui &amp; Teruskan', false);

            setujuiPermohonan($application, $giliran);
        }

        $this->actingAs($this->kasubbag)
            ->get(route('item-applications.show', $application))
            ->assertOk()
            ->assertDontSee('Setujui &amp; Teruskan', false);
    });

    it('tidak menampilkan tahapan pada permohonan dari sebelum alur bertahap', function () {
        $lama = ItemApplication::create([
            'application_number' => 'PMH-LAMA',
            'user_id' => $this->pemohon->user_id,
            'date' => now(),
            'purpose' => 'Kebutuhan rutin',
            'status' => ItemApplicationStatus::DisetujuiKepalaUnit,
        ]);
        $lama->details()->create(['type' => 'tambah_stok', 'item_id' => $this->item->item_id, 'quantity' => 3]);

        $this->actingAs($this->gudang)
            ->get(route('item-applications.show', $lama))
            ->assertOk()
            ->assertDontSee('Tahapan Persetujuan')
            ->assertDontSee('Unduh Nota Dinas')
            ->assertSee('Setujui &amp; Teruskan', false);
    });
});

describe('persetujuan bertahap', function () {
    it('hanya menerima keputusan dari yang gilirannya', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);
        $kepalaLain = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianSdm]);

        $ditolakSemua = function (array $akun, ItemApplicationStatus $status) use ($application) {
            foreach ($akun as $user) {
                $this->actingAs($user)
                    ->patch(route('item-applications.approve', $application))
                    ->assertSessionHas('error');
                $this->actingAs($user)
                    ->patch(route('item-applications.reject', $application), ['decision_note' => 'Bukan gilirannya.'])
                    ->assertSessionHas('error');
            }

            expect($application->fresh()->status)->toBe($status);
        };

        $ditolakSemua([$this->pemohon, $kepalaLain, $this->gudang, $this->kasubbag], ItemApplicationStatus::Diajukan);

        setujuiPermohonan($application, $this->kepala);
        $ditolakSemua([$this->pemohon, $this->kepala, $this->kasubbag], ItemApplicationStatus::DisetujuiKepalaUnit);

        setujuiPermohonan($application, $this->gudang);
        $ditolakSemua([$this->pemohon, $this->kepala, $this->gudang], ItemApplicationStatus::DitinjauGudang);
    });

    it('memindahkan tagihan mengikuti tahapnya', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);
        $ditagih = fn (NotificationType $type) => Notification::where('type', $type)->pluck('user_id')->all();

        expect($ditagih(NotificationType::PermohonanMenungguKepalaUnit))->toBe([$this->kepala->user_id])
            ->and($ditagih(NotificationType::PermohonanMenungguTinjauan))->toBe([])
            ->and($ditagih(NotificationType::PermohonanMenungguPersetujuanAkhir))->toBe([]);

        setujuiPermohonan($application, $this->kepala);

        expect($ditagih(NotificationType::PermohonanMenungguKepalaUnit))->toBe([])
            ->and($ditagih(NotificationType::PermohonanMenungguTinjauan))->toBe([$this->gudang->user_id])
            ->and($ditagih(NotificationType::PermohonanMenungguPersetujuanAkhir))->toBe([]);

        setujuiPermohonan($application, $this->gudang);

        expect($ditagih(NotificationType::PermohonanMenungguTinjauan))->toBe([])
            ->and($ditagih(NotificationType::PermohonanMenungguPersetujuanAkhir))->toBe([$this->kasubbag->user_id]);

        setujuiPermohonan($application, $this->kasubbag);

        expect($ditagih(NotificationType::PermohonanMenungguPersetujuanAkhir))->toBe([]);
    });

    it('mengabarkan pemohon di setiap tahap tanpa menimpa kabar sebelumnya', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);
        $kabar = fn () => Notification::where('user_id', $this->pemohon->user_id)
            ->where('type', NotificationType::PermohonanDiperbarui)
            ->pluck('body');

        expect($kabar())->toHaveCount(0);

        setujuiPermohonan($application, $this->kepala);

        expect($kabar())->toHaveCount(1)
            ->and($kabar()->first())->toContain($this->kepala->fullname)
            ->and($kabar()->first())->toContain('Menunggu ditinjau Pengelola Gudang.');

        setujuiPermohonan($application, $this->gudang);

        expect($kabar())->toHaveCount(2)
            ->and($kabar()->implode(' '))->toContain('Menunggu persetujuan akhir Kasubbag Umum dan TI.');

        setujuiPermohonan($application, $this->kasubbag);

        // Keputusan akhir punya jenisnya sendiri.
        expect($kabar())->toHaveCount(2)
            ->and(Notification::where('user_id', $this->pemohon->user_id)
                ->where('type', NotificationType::PermohonanDisetujui)->exists())->toBeTrue();
    });

    it('tidak mengabari pemohon atas tahap yang ia setujui sendiri', function () {
        $application = ajukanPermohonan($this->kepala, [barisTambahStokPermohonan($this->item, 5)]);
        $kabar = fn () => Notification::where('user_id', $this->kepala->user_id)
            ->where('type', NotificationType::PermohonanDiperbarui)
            ->count();

        setujuiPermohonan($application, $this->kepala);
        expect($kabar())->toBe(0);

        setujuiPermohonan($application, $this->gudang);
        expect($kabar())->toBe(1);
    });

    it('mencatat siapa yang menyetujui tiap tahap', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);

        setujuiPermohonan($application, $this->kepala, $this->gudang, $this->kasubbag);
        $application->refresh();

        expect($application->head_approved_by)->toBe($this->kepala->user_id)
            ->and($application->head_approved_at)->not->toBeNull()
            ->and($application->reviewed_by)->toBe($this->gudang->user_id)
            ->and($application->reviewed_at)->not->toBeNull()
            ->and($application->decided_by)->toBe($this->kasubbag->user_id)
            ->and(collect($application->tahapan())->pluck('keadaan')->all())->toBe(['selesai', 'selesai', 'selesai']);
    });

    it('belum meneruskan apa pun ke pengadaan sebelum keputusan akhir', function () {
        $application = ajukanPermohonan($this->pemohon, [
            barisTambahStokPermohonan($this->item, 5),
            barisBarangBaruPermohonan(),
        ]);

        setujuiPermohonan($application, $this->kepala, $this->gudang);

        expect($application->fresh()->status)->toBe(ItemApplicationStatus::DitinjauGudang)
            ->and(ProcurementRequest::count())->toBe(0)
            ->and(ItemProposal::count())->toBe(0)
            ->and(Notification::where('type', NotificationType::PermohonanDisetujui)->exists())->toBeFalse();
    });

    it('disetujui Kasubbag sebagai pimpinan untuk permohonan unitnya sendiri', function () {
        $staf = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianUmumTi]);
        $application = ajukanPermohonan($staf, [barisTambahStokPermohonan($this->item, 5)]);

        expect(Notification::where('type', NotificationType::PermohonanMenungguKepalaUnit)->pluck('user_id')->all())
            ->toBe([$this->kasubbag->user_id]);

        setujuiPermohonan($application, $this->kasubbag);

        expect($application->fresh()->status)->toBe(ItemApplicationStatus::DisetujuiKepalaUnit)
            ->and($application->fresh()->head_approved_by)->toBe($this->kasubbag->user_id);
    });

    it('boleh diputuskan Admin di setiap tahap', function () {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);

        setujuiPermohonan($application, $admin, $admin, $admin);

        expect($application->fresh()->status)->toBe(ItemApplicationStatus::Disetujui);
    });
});

describe('keputusan akhir', function () {
    it('meneruskan tambah stok ke draf usulan dan barang baru ke usulan barang', function () {
        $application = ajukanPermohonan($this->pemohon, [
            barisTambahStokPermohonan($this->item, 20),
            barisBarangBaruPermohonan(),
        ]);

        setujuiPermohonan($application, $this->kepala, $this->gudang);

        $this->actingAs($this->kasubbag)
            ->patch(route('item-applications.approve', $application))
            ->assertRedirect(route('item-applications.show', $application));

        $application->refresh()->load('details');
        $draft = ProcurementRequest::query()->draf()->sole();
        $line = $draft->details()->where('item_id', $this->item->item_id)->sole();
        $proposal = ItemProposal::sole();

        expect($application->status)->toBe(ItemApplicationStatus::Disetujui)
            ->and($application->decided_by)->toBe($this->kasubbag->user_id)
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

        // Pemohon diberi tahu.
        expect(Notification::where('user_id', $this->pemohon->user_id)
            ->where('type', NotificationType::PermohonanDisetujui)->exists())->toBeTrue();

        $this->actingAs($this->pemohon)->get(route('item-applications.show', $application))->assertOk();
    });

    it('menandai baris draf dan usulan yang berasal dari permohonan', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 6)]);
        setujuiPermohonan($application, $this->kepala, $this->gudang, $this->kasubbag);

        $this->actingAs($this->gudang)->get(route('procurement-requests.draft.edit'))
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
        setujuiPermohonan($application, $this->kepala, $this->gudang, $this->kasubbag);

        $line = ProcurementRequestDetail::where('item_id', $this->item->item_id)->sole();

        expect(ProcurementRequest::count())->toBe(1)
            ->and($line->amount_request)->toBe(12)
            ->and($line->is_auto)->toBeFalse()
            ->and($line->source)->toBe(ProcurementRequestSource::DariPermohonan);
    });

    it('tidak dapat diputuskan dua kali', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);

        setujuiPermohonan($application, $this->kepala, $this->gudang, $this->kasubbag);
        $this->actingAs($this->kasubbag)
            ->patch(route('item-applications.approve', $application))
            ->assertSessionHas('error');

        expect(ProcurementRequestDetail::sole()->amount_request)->toBe(5);
    });
});

describe('menolak permohonan', function () {
    it('mewajibkan alasan', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);

        $this->actingAs($this->kepala)
            ->patch(route('item-applications.reject', $application), ['decision_note' => ''])
            ->assertSessionHasErrors('decision_note');

        expect($application->fresh()->status)->toBe(ItemApplicationStatus::Diajukan);
    });

    it('boleh ditolak di setiap tahap dan menandai tahap penolaknya', function () {
        $penyetuju = [$this->kepala, $this->gudang, $this->kasubbag];

        foreach ($penyetuju as $tahap => $penolak) {
            $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);
            setujuiPermohonan($application, ...array_slice($penyetuju, 0, $tahap));

            $this->actingAs($penolak)
                ->patch(route('item-applications.reject', $application), ['decision_note' => 'Belum diperlukan.'])
                ->assertSessionMissing('error');

            $application->refresh();

            expect($application->status)->toBe(ItemApplicationStatus::Ditolak)
                ->and($application->decided_by)->toBe($penolak->user_id)
                ->and($application->tahapan()[$tahap]['keadaan'])->toBe('ditolak')
                ->and($application->tahapan()[$tahap]['oleh']->user_id)->toBe($penolak->user_id);

            $this->actingAs($this->pemohon)->get(route('item-applications.show', $application))->assertOk();
        }

        expect(Notification::where('type', NotificationType::PermohonanMenungguKepalaUnit)->exists())->toBeFalse()
            ->and(Notification::where('type', NotificationType::PermohonanMenungguTinjauan)->exists())->toBeFalse()
            ->and(Notification::where('type', NotificationType::PermohonanMenungguPersetujuanAkhir)->exists())->toBeFalse();
    });

    it('menutup permohonan tanpa meneruskan apa pun', function () {
        $application = ajukanPermohonan($this->pemohon, [
            barisTambahStokPermohonan($this->item, 5),
            barisBarangBaruPermohonan(),
        ]);
        setujuiPermohonan($application, $this->kepala);

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

describe('nota dinas permohonan', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->app->instance(ItemApplicationDocumentGenerator::class, new ItemApplicationDocumentGenerator);
    });

    it('terbit bernomor saat diajukan dan hanya dibuka yang boleh melihat permohonannya', function () {
        $application = ajukanPermohonan($this->pemohon, [
            barisTambahStokPermohonan($this->item, 5),
            barisBarangBaruPermohonan(),
        ]);
        $lain = User::factory()->role(UserRole::Pengguna)->create();

        expect($application->nd_number)->toEndWith('/B/ND/DJPKN-V.BLP.1.5/BMN.09.03/'.now()->format('n/Y'))
            ->and($application->nd_verification_hash)->toBeNull()
            ->and($application->perihalNotaDinas())->toBe('Permohonan ATK/ARTK Subbagian Hukum Bulan '.now()->locale('id')->translatedFormat('F Y'));

        Storage::disk('local')->assertExists($application->notaDinasPath());

        $this->actingAs($this->pemohon)
            ->get(route('item-applications.nota-dinas', $application))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->actingAs($lain)->get(route('item-applications.nota-dinas', $application))->assertForbidden();

        $this->actingAs($this->pemohon)
            ->get(route('item-applications.show', $application))
            ->assertSee('Unduh Nota Dinas')
            ->assertSee($application->nd_number);
    });

    it('mengisi format Nota Dinas Permohonan ATK/ARTK tanpa merusak DOCX', function () {
        $this->kepala->update(['fullname' => 'Uji Kepala Hukum', 'nip' => '198001012005011001']);
        $application = ajukanPermohonan($this->pemohon, [
            barisTambahStokPermohonan($this->item, 1200),
            barisBarangBaruPermohonan('Tinta Printer H&P <Hitam>', 2),
        ]);

        $isi = isiNotaDinasPermohonan($application);

        // DOCX tetap XML yang sah walau nama barang memuat & dan <.
        expect(fn () => (new DOMDocument)->loadXML($isi['xml']))->not->toThrow(Throwable::class);

        $teks = strip_tags($isi['xml']);

        foreach ([
            'NOTA DINAS',
            $application->nd_number,
            'Kepala Subbagian Umum dan TI',
            'Kepala Subbagian Hukum',
            $application->perihalNotaDinas(),
            'Bandar Lampung, '.now()->locale('id')->translatedFormat('j F Y'),
            'fungsi staf Subbagian Hukum',
            $this->item->item_name,
            '1.200',
            'Tinta Printer H&amp;P &lt;Hitam&gt;',
            'Uji Kepala Hukum',
            'NIP 198001012005011001',
            'Tembusan',
        ] as $potongan) {
            expect($teks)->toContain($potongan);
        }

        expect($isi['qr'])->toBe([]);
    });

    it('ditandatangani dengan QR saat disetujui pimpinan unit', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);
        $sebelum = Storage::disk('local')->get($application->notaDinasPath());

        setujuiPermohonan($application, $this->kepala);

        $isi = isiNotaDinasPermohonan($application);

        expect($application->fresh()->nd_verification_hash)->not->toBeNull()
            ->and(Storage::disk('local')->get($application->notaDinasPath()))->not->toBe($sebelum)
            ->and($isi['qr'])->toBe(['qr-kepala-unit.png'])
            ->and($isi['xml'])->toContain('r:embed="rIdQr1"');

        // Tahap berikutnya tidak menambah QR pada Nota Dinas.
        setujuiPermohonan($application, $this->gudang, $this->kasubbag);

        expect(isiNotaDinasPermohonan($application)['qr'])->toBe(['qr-kepala-unit.png']);
    });

    it('mencetak Admin sebagai penanda tangan bila Admin yang menyetujui', function () {
        $admin = User::factory()->role(UserRole::Admin)->create(['fullname' => 'Uji Admin Sistem']);
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);

        setujuiPermohonan($application, $admin);

        $teks = strip_tags(isiNotaDinasPermohonan($application)['xml']);

        expect($teks)->toContain('Uji Admin Sistem')
            ->and($teks)->toContain('Kepala Subbagian Hukum,');
    });

    it('membuka halaman verifikasi tanpa login hanya dengan token yang benar', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);
        setujuiPermohonan($application, $this->kepala);
        $application->refresh()->forceFill(['nd_verification_hash' => hash('sha256', 'token-benar')])->save();

        auth()->logout();

        $this->get(route('item-applications.nota-dinas.verify', ['itemApplication' => $application, 'token' => 'token-benar']))
            ->assertOk()
            ->assertSee('Dokumen Terverifikasi')
            ->assertSee($application->nd_number)
            ->assertSee($application->application_number)
            ->assertSee($this->kepala->fullname);

        $this->get(route('item-applications.nota-dinas.verify', ['itemApplication' => $application, 'token' => 'token-salah']))
            ->assertNotFound();
    });

    it('menolak nomor yang sudah dipakai permohonan atau permintaan, dan sebaliknya', function () {
        $application = ajukanPermohonan($this->pemohon, [barisTambahStokPermohonan($this->item, 5)]);
        $kirim = fn (string $nomorUrut) => $this->actingAs($this->pemohon)->post(route('item-applications.store'), [
            'nd_sequence' => $nomorUrut,
            'purpose' => 'Kebutuhan rutin',
            'items' => [barisTambahStokPermohonan($this->item, 1)],
        ]);

        $kirim(Str::before($application->nd_number, '/'))->assertSessionHasErrors('nd_sequence');

        $permintaan = ModelsRequest::create([
            'user_id' => $this->pemohon->user_id,
            'date' => now(),
            'type' => 'normal',
            'status' => 'submitted',
            'request_number' => 'REQ-'.Str::random(8),
        ]);
        app(RequestWorkflowService::class)->assignNotaDinasNumber($permintaan, '9001');

        $kirim('9001')->assertSessionHasErrors('nd_sequence');

        expect(ItemApplication::count())->toBe(1)
            ->and(fn () => app(RequestWorkflowService::class)->assignNotaDinasNumber(
                $permintaan->fresh(),
                Str::before($application->nd_number, '/'),
            ))->toThrow(ValidationException::class);
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
