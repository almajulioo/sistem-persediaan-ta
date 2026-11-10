<?php

use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\User;
use App\Support\BastQrStamper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/*
 * QR verifikasi pada BAST permintaan: satu per persetujuan, ditempel ke kolom
 * tanda tangan tanpa mengubah templatenya. Helper bersufiks `BastQr` supaya
 * tidak bertabrakan dengan helper global berkas lain.
 */

function permintaanBastQr(User $pemohon, Item $item, string $status, string $type = 'normal'): ModelsRequest
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

    return $request;
}

function setujuiBastQr(ModelsRequest $request, User $actor): void
{
    test()->actingAs($actor)
        ->post(route('request.respond', $request), ['action' => 'approve'])
        ->assertSessionMissing('error');
}

function penerimaanBastQr(ModelsRequest $request, User $actor, string $action, ?string $note = null): void
{
    test()->actingAs($actor)
        ->post(route('request.receipt', $request), ['action' => $action, 'note' => $note])
        ->assertSessionMissing('error');
}

/**
 * @return array{xml: string, relasi: string, tipe: string, qr: array<string, string>}
 */
function isiBastQr(ModelsRequest $request): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path("bast-permintaan/{$request->request_id}.docx"));

    $qr = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nama = $zip->getNameIndex($i);
        if (str_starts_with($nama, 'word/media/qr-')) {
            $qr[Str::between($nama, 'qr-', '.png')] = $zip->getFromIndex($i);
        }
    }

    $isi = [
        'xml' => $zip->getFromName('word/document.xml'),
        'relasi' => $zip->getFromName('word/_rels/document.xml.rels'),
        'tipe' => $zip->getFromName('[Content_Types].xml'),
        'qr' => $qr,
    ];
    $zip->close();

    return $isi;
}

function tautanBastQr(RequestHistory $history): string
{
    return URL::signedRoute('request.bast.verify', ['requestHistory' => $history], absolute: false);
}

beforeEach(function () {
    Storage::fake('local');

    $this->pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum, 'fullname' => 'Uji Pemohon']);
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create(['unit' => UnitKerja::SubbagianUmumTi, 'fullname' => 'Uji Gudang']);
    $this->kasubbag = User::factory()->role(UserRole::KasubbagUmumTi)->create(['unit' => UnitKerja::SubbagianUmumTi]);
    $this->kertas = Item::factory()->create(['item_name' => 'Kertas A4', 'stock_current' => 10]);
});

describe('QR di dokumen', function () {
    it('menambah QR mengikuti persetujuan tanpa menyentuh isi template', function () {
        $template = resource_path('document/template-bast-persediaan.docx');
        $sidikTemplate = md5_file($template);
        $request = permintaanBastQr($this->pemohon, $this->kertas, 'processed_by_staff');

        setujuiBastQr($request, $this->kasubbag);
        $dimulai = isiBastQr($request);

        // Terbit sejak Kasubbag memulai pengeluaran; PIHAK KESATU menyusul.
        expect(array_keys($dimulai['qr']))->toBe([BastQrStamper::MENGETAHUI])
            ->and(strip_tags($dimulai['xml']))->toContain('Nama: ……')
            ->and(strip_tags($dimulai['xml']))->not->toContain('Uji Gudang');

        setujuiBastQr($request, $this->gudang);
        $diserahkan = isiBastQr($request);

        expect(strip_tags($diserahkan['xml']))->toContain('Uji Gudang')
            ->and(strip_tags($diserahkan['xml']))->not->toContain('Nama: ……')
            ->and($diserahkan['qr'][BastQrStamper::MENGETAHUI])->toBe($dimulai['qr'][BastQrStamper::MENGETAHUI]);

        expect(array_keys($diserahkan['qr']))->toEqualCanonicalizing([BastQrStamper::MENGETAHUI, BastQrStamper::PIHAK_KESATU])
            ->and($diserahkan['tipe'])->toContain('Extension="png"')
            ->and($diserahkan['relasi'])->toContain('media/qr-mengetahui.png')
            ->and($diserahkan['xml'])->toContain('r:embed="rIdQr1"');

        penerimaanBastQr($request, $this->pemohon, 'confirm');
        $selesai = isiBastQr($request);

        expect(array_keys($selesai['qr']))->toEqualCanonicalizing([
            BastQrStamper::MENGETAHUI,
            BastQrStamper::PIHAK_KESATU,
            BastQrStamper::PIHAK_KEDUA,
        ]);

        // QR lama tidak berubah, jadi salinan yang sudah diunduh tetap berlaku.
        expect($selesai['qr'][BastQrStamper::MENGETAHUI])->toBe($diserahkan['qr'][BastQrStamper::MENGETAHUI])
            ->and($selesai['qr'][BastQrStamper::PIHAK_KESATU])->toBe($diserahkan['qr'][BastQrStamper::PIHAK_KESATU]);

        // Tanpa tag: Word memecah satu kalimat ke beberapa potongan XML.
        $teksDokumen = strip_tags($selesai['xml']);

        foreach (['BERITA ACARA SERAH TERIMA PERSEDIAAN', 'PIHAK KESATU', 'PIHAK KEDUA', 'Mengetahui,', 'Kepala Subbagian Umum', 'Uji Pemohon', 'Kertas A4'] as $teks) {
            expect($teksDokumen)->toContain($teks);
        }

        expect(md5_file($template))->toBe($sidikTemplate);
    });

    it('mempertahankan tanggal dan PIHAK KESATU saat dicetak ulang', function () {
        $request = permintaanBastQr($this->pemohon, $this->kertas, 'in_issuance');

        setujuiBastQr($request, $this->gudang);
        $bast = RequestDocument::where('document_type', 'bast')->sole();
        $tanggal = $bast->approved_at->translatedFormat('j F Y');

        $this->travel(5)->days();
        penerimaanBastQr($request, $this->pemohon, 'confirm');

        $bast->refresh();
        $isi = isiBastQr($request);

        expect($bast->approved_by_user_id)->toBe($this->gudang->user_id)
            ->and($bast->approved_at->translatedFormat('j F Y'))->toBe($tanggal)
            ->and(strip_tags($isi['xml']))->toContain($tanggal)
            ->and(strip_tags($isi['xml']))->toContain('Uji Gudang')
            ->and(strip_tags($isi['xml']))->not->toContain(now()->translatedFormat('j F Y'));
    });

    it('tidak mencetak QR Mengetahui pada permintaan darurat', function () {
        $request = permintaanBastQr($this->pemohon, $this->kertas, 'approved_by_head', 'emergency');

        setujuiBastQr($request, $this->gudang);
        penerimaanBastQr($request, $this->pemohon, 'confirm');

        expect(array_keys(isiBastQr($request)['qr']))
            ->toEqualCanonicalizing([BastQrStamper::PIHAK_KESATU, BastQrStamper::PIHAK_KEDUA]);
    });

    it('tetap menghasilkan DOCX yang sah walau nama barang dan pemohon memuat & dan <', function () {
        $pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum, 'fullname' => 'Uji Sari & Rekan']);
        $tinta = Item::factory()->create(['item_name' => 'Tinta Printer H&P <Hitam>', 'stock_current' => 10]);
        $request = permintaanBastQr($pemohon, $tinta, 'in_issuance');

        setujuiBastQr($request, $this->gudang);
        penerimaanBastQr($request, $pemohon, 'confirm');

        $xml = isiBastQr($request)['xml'];

        expect(fn () => (new DOMDocument)->loadXML($xml))->not->toThrow(Throwable::class)
            ->and((new DOMDocument)->loadXML($xml))->toBeTrue()
            ->and(strip_tags($xml))->toContain('Tinta Printer H&amp;P &lt;Hitam&gt;')
            ->and(strip_tags($xml))->toContain('Uji Sari &amp; Rekan');
    });

    it('gagal keras bila kolom tanda tangan template tidak ditemukan', function () {
        $docx = tempnam(sys_get_temp_dir(), 'bast-');
        $zip = new ZipArchive;
        $zip->open($docx, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        $zip->addFromString('word/_rels/document.xml.rels', '<Relationships/>');
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->close();

        expect(fn () => (new BastQrStamper)->stamp($docx, [BastQrStamper::PIHAK_KESATU => 'png']))
            ->toThrow(RuntimeException::class, 'Kolom tanda tangan');

        unlink($docx);
    });
});

describe('BAST sebelum diserahkan', function () {
    beforeEach(function () {
        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->request = permintaanBastQr($this->pemohon, $this->kertas, 'processed_by_staff');
        setujuiBastQr($this->request, $this->kasubbag);
        $this->tautanLama = tautanBastQr($this->request->refresh()->persetujuanBast()[BastQrStamper::MENGETAHUI]);
    });

    it('membatalkan QR Mengetahui dan menyembunyikan BAST bila status dikembalikan', function () {
        $this->get(route('request.show', $this->request))->assertSee('Unduh BAST');

        $this->actingAs($this->admin)
            ->post(route('request.revert-status', $this->request), ['reason' => 'Salah klik mulai pengeluaran.'])
            ->assertSessionMissing('error');

        expect($this->request->refresh()->status)->toBe('processed_by_staff');

        $this->get($this->tautanLama)->assertNotFound();
        $this->get(route('request.bast', $this->request))->assertNotFound();
        $this->get(route('request.show', $this->request))->assertDontSee('Unduh BAST');

        // Disetujui ulang: QR baru berlaku, yang lama tetap tidak.
        setujuiBastQr($this->request, $this->kasubbag);
        $tautanBaru = tautanBastQr($this->request->fresh()->persetujuanBast()[BastQrStamper::MENGETAHUI]);

        expect($tautanBaru)->not->toBe($this->tautanLama);
        $this->get($tautanBaru)->assertOk();
        $this->get($this->tautanLama)->assertNotFound();
    });

    it('membatalkan QR Mengetahui bila ditolak saat pengeluaran', function () {
        $this->actingAs($this->gudang)
            ->post(route('request.respond', $this->request), ['action' => 'reject', 'note' => 'Barang rusak di gudang.'])
            ->assertSessionMissing('error');

        expect($this->request->refresh()->status)->toBe('rejected');

        $this->get($this->tautanLama)->assertNotFound();
        $this->get(route('request.bast', $this->request))->assertNotFound();
    });
});

describe('halaman verifikasi', function () {
    beforeEach(function () {
        $this->request = permintaanBastQr($this->pemohon, $this->kertas, 'processed_by_staff');
        setujuiBastQr($this->request, $this->kasubbag);
        setujuiBastQr($this->request, $this->gudang);
        // Status berubah lewat HTTP; persetujuanBast() bergantung padanya.
        $this->request->refresh();
    });

    it('terbuka tanpa login dan menyebut persetujuan yang tepat', function () {
        $persetujuan = $this->request->persetujuanBast();

        auth()->logout();

        $this->get(tautanBastQr($persetujuan[BastQrStamper::MENGETAHUI]))
            ->assertOk()
            ->assertSee('Dokumen Terverifikasi')
            ->assertSee('Berita Acara Serah Terima Persediaan')
            ->assertSeeInOrder(['Dokumen', 'Pemohon', 'Persetujuan'])
            ->assertDontSee('ditutup petugas atas nama pemohon')
            ->assertSee('Berita acara serah terima')
            ->assertSee('51/B/BAST/DJPKN-V.BLP.1.5/KEU.02.02/11/2026')
            ->assertSee('Mengetahui')
            ->assertSee($this->kasubbag->fullname);

        $this->get(tautanBastQr($persetujuan[BastQrStamper::PIHAK_KESATU]))
            ->assertOk()
            ->assertSee('PIHAK KESATU')
            ->assertSee($this->gudang->fullname);
    });

    it('menolak tautan yang diubah', function () {
        $persetujuan = $this->request->persetujuanBast();
        $tautan = tautanBastQr($persetujuan[BastQrStamper::MENGETAHUI]);

        $this->get(str_replace(
            '/bast/'.$persetujuan[BastQrStamper::MENGETAHUI]->getKey(),
            '/bast/'.$persetujuan[BastQrStamper::PIHAK_KESATU]->getKey(),
            $tautan,
        ))->assertForbidden();

        $this->get(Str::before($tautan, '?'))->assertForbidden();
    });

    it('menolak baris riwayat yang bukan persetujuan BAST', function () {
        $lain = RequestHistory::create([
            'request_id' => $this->request->request_id,
            'user_id' => $this->gudang->user_id,
            'action' => \App\Enums\RequestHistoryAction::MasalahDitanggapi,
            'from_status' => 'delivered',
            'to_status' => 'delivered',
        ]);

        $this->get(tautanBastQr($lain))->assertNotFound();
    });

    it('tidak mengaku pemohon yang menyetujui saat ditutup petugas', function () {
        $this->travel(\App\Models\Request::BATAS_HARI_KONFIRMASI + 1)->days();

        penerimaanBastQr($this->request, $this->gudang, 'close', 'Pemohon tidak dapat dihubungi.');

        $persetujuan = $this->request->refresh()->persetujuanBast();

        expect(array_keys(isiBastQr($this->request)['qr']))->toContain(BastQrStamper::PIHAK_KEDUA);

        $this->get(tautanBastQr($persetujuan[BastQrStamper::PIHAK_KEDUA]))
            ->assertOk()
            ->assertSee('PIHAK KEDUA')
            ->assertSee('Ditutup '.$this->gudang->fullname.' atas nama pemohon')
            ->assertSee('Pemohon sendiri tidak mengonfirmasi penerimaan barang.');
    });
});
