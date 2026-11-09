<?php

use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDocument;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * Halaman publik tujuan QR Nota Dinas permintaan. BAST dan Nota Dinas
 * permohonan diuji di BastQrTest dan PermohonanTest. Helper bersufiks
 * `VerifikasiDokumen` supaya tidak bertabrakan dengan helper global berkas lain.
 */

function notaDinasVerifikasiDokumen(string $token): RequestDocument
{
    $pemohon = User::factory()->role(UserRole::Pengguna)->create(['unit' => UnitKerja::SubbagianHukum, 'fullname' => 'Uji Pemohon']);
    $kepala = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::SubbagianHukum, 'fullname' => 'Uji Kepala']);
    $request = ModelsRequest::create([
        'user_id' => $pemohon->user_id,
        'date' => now(),
        'type' => 'normal',
        'status' => 'approved_by_head',
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
        'nd_number' => '51/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026',
    ]);

    return RequestDocument::create([
        'request_id' => $request->request_id,
        'user_id' => $pemohon->user_id,
        'document_type' => 'nota_dinas',
        'document_number' => $request->nd_number,
        'file_path' => "nota-dinas/{$request->request_id}.pdf",
        'approved_by_user_id' => $kepala->user_id,
        'approved_at' => Carbon::parse('2026-05-04 14:05:00'),
        'verification_token_hash' => hash('sha256', $token),
    ]);
}

it('menampilkan Nota Dinas permintaan per bagian, tanpa login', function () {
    $document = notaDinasVerifikasiDokumen('token-benar');

    $this->get(route('request.nota-dinas.verify', ['requestDocument' => $document, 'token' => 'token-benar']))
        ->assertOk()
        ->assertSee('Dokumen Terverifikasi')
        ->assertSee('Nota Dinas Permintaan Barang')
        ->assertSeeInOrder([
            'Dokumen', '51/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/11/2026', $document->request->request_number,
            'Pemohon', 'Uji Pemohon', 'Subbagian Hukum',
            'Persetujuan', 'Uji Kepala', 'Kepala Subbagian Hukum', '4 Mei 2026, 14.05 WIB',
        ])
        ->assertDontSee('role="note"', false);
});

it('menolak token yang salah', function () {
    $document = notaDinasVerifikasiDokumen('token-benar');

    $this->get(route('request.nota-dinas.verify', ['requestDocument' => $document, 'token' => 'token-salah']))
        ->assertNotFound();
});
