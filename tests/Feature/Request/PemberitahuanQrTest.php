<?php

use App\Enums\ItemApplicationStatus;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemApplication;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\RequestDocument;
use App\Models\User;
use App\Support\BastQrStamper;
use App\Support\PemberitahuanQr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Pemberitahuan di modal persetujuan yang menghasilkan QR. Helper bersufiks
 * `PemberitahuanQr` supaya tidak bertabrakan dengan helper global berkas lain.
 */

function permintaanPemberitahuanQr(User $pemohon, Item $item, string $status, string $type = 'normal'): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => $pemohon->user_id,
        'date' => now(),
        'type' => $type,
        'status' => $status,
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
        'nd_number' => '51/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
        'bast_number' => '51/B/BAST/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
    ]);

    RequestDetail::create([
        'request_id' => $request->request_id,
        'item_id' => $item->item_id,
        'quantity_requested' => 2,
        'quantity_approved' => 2,
    ]);

    return $request->load('user');
}

function permohonanPemberitahuanQr(User $pemohon, Item $item, ItemApplicationStatus $status, int $nomorUrut = 60): ItemApplication
{
    $application = ItemApplication::create([
        'application_number' => 'PMH-'.Str::upper(Str::random(8)),
        'nd_number' => "{$nomorUrut}/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026",
        'user_id' => $pemohon->user_id,
        'date' => now(),
        'purpose' => 'Kebutuhan rutin',
        'status' => $status,
    ]);
    $application->details()->create(['type' => 'tambah_stok', 'item_id' => $item->item_id, 'quantity' => 3]);

    return $application;
}

function tandatanganiNotaDinasPemberitahuanQr(ModelsRequest $request, User $penanda): void
{
    RequestDocument::create([
        'request_id' => $request->request_id,
        'user_id' => $request->user_id,
        'document_type' => 'nota_dinas',
        'document_number' => $request->nd_number,
        'file_path' => "nota-dinas/{$request->request_id}.pdf",
        'approved_by_user_id' => $penanda->user_id,
        'approved_at' => now(),
        'verification_token_hash' => hash('sha256', Str::random(16)),
    ]);
}

beforeEach(function () {
    Storage::fake('local');

    $this->pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum]);
    $this->kepala = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianHukum]);
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create(['unit' => UnitKerja::SubbagianUmumTi]);
    $this->kasubbag = User::factory()->role(UserRole::KasubbagUmumTi)->create(['unit' => UnitKerja::SubbagianUmumTi]);
    $this->kertas = Item::factory()->create(['item_name' => 'Kertas A4', 'stock_current' => 10]);
});

describe('isi pemberitahuan', function () {
    it('menyebut dokumen dan kolom yang tepat di setiap tahap permintaan', function () {
        $qr = fn (string $status, string $type = 'normal') => PemberitahuanQr::persetujuan(
            permintaanPemberitahuanQr($this->pemohon, $this->kertas, $status, $type),
        );

        expect($qr('submitted'))->toMatchArray([
            'dokumen' => 'Nota Dinas 51/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
            'kolom' => 'Kepala Subbagian Hukum',
        ])
            ->and($qr('processed_by_staff'))->toMatchArray([
                'dokumen' => 'BAST 51/B/BAST/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
                'kolom' => 'Mengetahui',
            ])
            ->and($qr('in_issuance')['kolom'])->toBe('PIHAK KESATU')
            ->and($qr('approved_by_head', 'emergency')['kolom'])->toBe('PIHAK KESATU')
            ->and($qr('approved_by_head', 'emergency')['catatan'])->toContain('Kolom Mengetahui kosong');
    });

    it('tidak memberi tahu pada tahap yang tidak menghasilkan QR', function () {
        foreach (['approved_by_head', 'delivered', 'completed', 'rejected'] as $status) {
            expect(PemberitahuanQr::persetujuan(permintaanPemberitahuanQr($this->pemohon, $this->kertas, $status)))->toBeNull();
        }
    });

    it('membedakan konfirmasi pemohon dari penutupan oleh petugas', function () {
        $request = permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'delivered');

        expect(PemberitahuanQr::penerimaan($request, 'confirm')['kolom'])->toBe('PIHAK KEDUA')
            ->and(PemberitahuanQr::penerimaan($request, 'close')['catatan'])->toContain('atas nama pemohon')
            ->and(PemberitahuanQr::penerimaan($request, 'report'))->toBeNull()
            ->and(PemberitahuanQr::penerimaan($request, 'reply'))->toBeNull();
    });

    it('hanya memberi tahu di tahap Kepala Unit pada permohonan', function () {
        foreach (ItemApplicationStatus::cases() as $urutan => $status) {
            $pemberitahuan = PemberitahuanQr::permohonan(permohonanPemberitahuanQr($this->pemohon, $this->kertas, $status, 60 + $urutan));

            if ($status === ItemApplicationStatus::Diajukan) {
                expect($pemberitahuan)->toMatchArray([
                    'dokumen' => 'Nota Dinas 60/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
                    'kolom' => 'Kepala Subbagian Hukum',
                ]);
            } else {
                expect($pemberitahuan)->toBeNull();
            }
        }
    });

    it('sejalan dengan QR yang benar-benar dibuat', function () {
        $request = permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'processed_by_staff');

        foreach ([
            [$this->kasubbag, 'Mengetahui', BastQrStamper::MENGETAHUI],
            [$this->gudang, 'PIHAK KESATU', BastQrStamper::PIHAK_KESATU],
        ] as [$penyetuju, $kolom, $kunci]) {
            expect(PemberitahuanQr::persetujuan($request->fresh('user'))['kolom'])->toBe($kolom);

            $this->actingAs($penyetuju)
                ->post(route('request.respond', $request), ['action' => 'approve'])
                ->assertSessionMissing('error');

            expect($request->fresh()->persetujuanBast())->toHaveKey($kunci);
        }
    });
});

describe('peringatan penerbitan ulang QR', function () {
    it('hanya berlaku pada Nota Dinas yang sudah ber-QR', function () {
        $request = permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'approved_by_head');

        expect(PemberitahuanQr::penerbitanUlang($request))->toBeNull();

        tandatanganiNotaDinasPemberitahuanQr($request, $this->kepala);

        expect(PemberitahuanQr::penerbitanUlang($request))->toMatchArray([
            'dokumen' => 'Nota Dinas 51/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
        ])
            ->and(PemberitahuanQr::penerbitanUlang($request)['catatan'])->toContain('tidak bisa diverifikasi lagi');
    });

    it('saat disetujui hanya berlaku pada permintaan yang belum bernomor', function () {
        $bernomor = permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'approved_by_head');
        $belum = permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'approved_by_head');
        $belum->update(['nd_number' => null]);

        tandatanganiNotaDinasPemberitahuanQr($bernomor, $this->kepala);
        tandatanganiNotaDinasPemberitahuanQr($belum, $this->kepala);

        expect(PemberitahuanQr::penerbitanUlangSaatDisetujui($bernomor))->toBeNull()
            ->and(PemberitahuanQr::penerbitanUlangSaatDisetujui($belum)['dokumen'])->toBe('Nota Dinas yang belum bernomor');

        $this->actingAs($this->gudang)
            ->get(route('request.show', $belum))
            ->assertOk()
            ->assertSee('QR lama tidak berlaku lagi')
            ->assertSee('Nota Dinas yang belum bernomor', false);
    });

    it('meminta konfirmasi di form ubah kuantitas hanya bila sudah ber-QR', function () {
        $request = permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'approved_by_head');

        $this->actingAs($this->gudang)
            ->get(route('request.edit', $request))
            ->assertOk()
            ->assertDontSee('Ubah nomor Nota Dinas?');

        tandatanganiNotaDinasPemberitahuanQr($request, $this->kepala);

        $this->get(route('request.edit', $request))
            ->assertOk()
            ->assertSee('Ubah nomor Nota Dinas?')
            ->assertSee('QR lama tidak berlaku lagi');
    });

    it('memberi peringatan yang sesuai kenyataan: QR lama memang berganti', function () {
        $this->app->instance(\App\Support\RequestDocumentGenerator::class, new \App\Support\RequestDocumentGenerator);
        $request = permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'approved_by_head');
        tandatanganiNotaDinasPemberitahuanQr($request, $this->kepala);
        $tokenLama = RequestDocument::where('document_type', 'nota_dinas')->value('verification_token_hash');

        $this->actingAs($this->gudang)
            ->put(route('request.update', $request), [
                'items' => [['item_id' => $this->kertas->item_id, 'qty' => 2]],
                'nd_sequence' => '77',
            ])
            ->assertSessionHasNoErrors();

        $bast = RequestDocument::where('document_type', 'nota_dinas')->sole();

        expect($bast->verification_token_hash)->not->toBe($tokenLama)
            ->and($bast->approved_by_user_id)->toBe($this->kepala->user_id)
            ->and($bast->document_number)->toStartWith('77/');
    });
});

describe('tampil di modal', function () {
    it('sampai ke modal di daftar, detail, dan dasbor Kepala Unit', function () {
        $request = permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'submitted');

        $this->actingAs($this->kepala);

        foreach ([route('request.index'), route('request.show', $request), route('dashboard')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Persetujuan ini membuat QR verifikasi')
                ->assertSee('Nota Dinas 51', false)
                ->assertSee('Kepala Subbagian Hukum', false);
        }
    });

    it('menyebut kolom BAST kepada Kasubbag dan Pengelola Gudang', function () {
        $this->actingAs($this->kasubbag)
            ->get(route('request.show', permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'processed_by_staff')))
            ->assertOk()
            ->assertSee('BAST terbit sekarang dengan QR ini', false);

        $this->actingAs($this->gudang)
            ->get(route('request.show', permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'in_issuance')))
            ->assertOk()
            ->assertSee('PIHAK KESATU', false);
    });

    it('menyebut PIHAK KEDUA saat pemohon mengonfirmasi', function () {
        $this->actingAs($this->pemohon)
            ->get(route('request.show', permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'delivered')))
            ->assertOk()
            ->assertSee('PIHAK KEDUA', false)
            ->assertSee('Persetujuan ini membuat QR verifikasi');
    });

    it('tidak mengirim isi pemberitahuan pada tahap tanpa QR', function () {
        $this->actingAs($this->gudang)
            ->get(route('request.show', permintaanPemberitahuanQr($this->pemohon, $this->kertas, 'approved_by_head')))
            ->assertOk()
            ->assertDontSee('PIHAK KESATU', false)
            ->assertDontSee('Nota Dinas 51', false);
    });

    it('tampil di modal Kepala Unit pada permohonan, tidak di tahap berikutnya', function () {
        $this->actingAs($this->kepala)
            ->get(route('item-applications.show', permohonanPemberitahuanQr($this->pemohon, $this->kertas, ItemApplicationStatus::Diajukan)))
            ->assertOk()
            ->assertSee('Persetujuan ini membuat QR verifikasi');

        $this->actingAs($this->gudang)
            ->get(route('item-applications.show', permohonanPemberitahuanQr($this->pemohon, $this->kertas, ItemApplicationStatus::DisetujuiKepalaUnit, 61)))
            ->assertOk()
            ->assertDontSee('Persetujuan ini membuat QR verifikasi');
    });
});
