<?php

use App\Enums\NotificationType;
use App\Enums\ProcurementDocumentType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Procurement;
use App\Models\ProcurementDocument;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentStorage;
use App\Support\NotificationScanner;
use App\Support\ProcurementReceiptPoster;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Nama pembantu di berkas Pest bersifat global; `buatUsulan`, `setujuiUsulan`,
// dan `buatRealisasi` sengaja dipakai ulang dari berkas uji Tahap 2 dan 3.

/**
 * Realisasi yang sudah dikonfirmasi diterima seluruhnya, siap dilampiri berkas.
 *
 * `$diterimaHariLalu` menggeser tanggal penerimaan ke belakang supaya ambang
 * notifikasi BastBelumDiunggah dapat diuji tanpa memanipulasi waktu sistem.
 */
function realisasiDiterima(User $pejabat, User $petugas, int $diterimaHariLalu = 0): Procurement
{
    $usulan = setujuiUsulan($pejabat, buatUsulan($petugas, [
        ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
    ]));

    $procurement = buatRealisasi($pejabat, $usulan, Supplier::factory()->create());

    ProcurementReceiptPoster::post(
        procurement: $procurement,
        diterima: $procurement->details
            ->mapWithKeys(fn ($detail) => [$detail->procurement_detail_id => $detail->amount])
            ->all(),
        actor: $petugas,
        bastNumber: '007/BAST/'.now()->year,
        receivedAt: now()->subDays($diterimaHariLalu),
    );

    return $procurement->fresh();
}

/**
 * Realisasi yang belum dikonfirmasi diterima.
 */
function realisasiMenunggu(User $pejabat, User $petugas): Procurement
{
    $usulan = setujuiUsulan($pejabat, buatUsulan($petugas, [
        ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
    ]));

    return buatRealisasi($pejabat, $usulan, Supplier::factory()->create());
}

beforeEach(function () {
    // Berkas uji tidak pernah menyentuh disk sungguhan (ADR 0018).
    Storage::fake(DocumentStorage::DISK);

    $this->petugas = pengelolaGudang();
    $this->pejabat = pejabatPengadaan();
    $this->procurement = realisasiDiterima($this->pejabat, $this->petugas);
    $this->url = "/pengadaan/{$this->procurement->procurement_id}/dokumen";
});

describe('hak akses berkas', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        // beforeEach membangun realisasinya lewat alur aplikasi, jadi sesi uji
        // masih membawa pengguna terakhir yang dipakai; keadaan tamu harus
        // dikembalikan secara eksplisit.
        auth()->logout();

        $this->post($this->url, ['type' => 'bast', 'file' => UploadedFile::fake()->create('bast.pdf', 10, 'application/pdf')])
            ->assertRedirect('/login');
    });

    it('menolak peran di luar pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->post($this->url, [
                'type' => 'bast',
                'file' => UploadedFile::fake()->create('bast.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();
    });

    it('mengizinkan Pengelola Gudang mengunggah BAST bertanda tangan', function () {
        $this->actingAs($this->petugas)
            ->post($this->url, [
                'type' => 'bast',
                'file' => UploadedFile::fake()->create('bast-ttd.pdf', 40, 'application/pdf'),
            ])
            ->assertRedirect();

        expect($this->procurement->fresh()->bastSudahDiunggah())->toBeTrue();
    });

    it('mengizinkan Pejabat Pengadaan mengunggah SPK', function () {
        $this->actingAs($this->pejabat)
            ->post($this->url, [
                'type' => 'spk_kontrak',
                'file' => UploadedFile::fake()->create('spk.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect();

        expect(ProcurementDocument::where('type', ProcurementDocumentType::SpkKontrak)->count())->toBe(1);
    });

    it('mengizinkan Admin mengunggah', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post($this->url, [
                'type' => 'bast',
                'file' => UploadedFile::fake()->create('bast.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect();

        expect(ProcurementDocument::count())->toBe(1);
    });
});

describe('mengunggah berkas', function () {
    it('menyimpan berkas di disk privat beserta identitas aslinya', function () {
        $this->actingAs($this->petugas)->post($this->url, [
            'type' => 'bast',
            'file' => UploadedFile::fake()->create('BAST Ditandatangani.pdf', 128, 'application/pdf'),
            'notes' => 'Hasil pindai lengkap tiga halaman.',
        ]);

        $document = ProcurementDocument::firstOrFail();

        expect($document->original_name)->toBe('BAST Ditandatangani.pdf')
            ->and($document->type)->toBe(ProcurementDocumentType::Bast)
            ->and($document->uploaded_by)->toBe($this->petugas->user_id)
            ->and($document->notes)->toBe('Hasil pindai lengkap tiga halaman.')
            ->and($document->file_size)->toBeGreaterThan(0);

        Storage::disk(DocumentStorage::DISK)->assertExists($document->file_path);

        // Nama berkas di disk diacak, tidak memakai nama asli.
        expect($document->file_path)->toContain($this->procurement->procurement_id)
            ->and($document->file_path)->not->toContain('BAST Ditandatangani');
    });

    it('mencatat jejak audit', function () {
        $this->actingAs($this->petugas)->post($this->url, [
            'type' => 'bast',
            'file' => UploadedFile::fake()->create('bast.pdf', 10, 'application/pdf'),
        ]);

        expect(AuditLog::where('activity', 'like', 'Mengunggah BAST Bertanda Tangan%')->exists())->toBeTrue();
    });

    it('menolak jenis berkas yang tidak diizinkan', function () {
        $this->actingAs($this->petugas)
            ->post($this->url, [
                'type' => 'bast',
                'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
            ])
            ->assertSessionHasErrors('file');

        expect(ProcurementDocument::count())->toBe(0);
    });

    it('menolak berkas melebihi 5 MB', function () {
        $this->actingAs($this->petugas)
            ->post($this->url, [
                'type' => 'bast',
                'file' => UploadedFile::fake()->create('besar.pdf', 6000, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');
    });

    it('menolak jenis dokumen yang tidak dikenal', function () {
        $this->actingAs($this->petugas)
            ->post($this->url, [
                'type' => 'faktur_pajak',
                'file' => UploadedFile::fake()->create('berkas.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('type');
    });

    it('menerima lebih dari satu berkas pada satu pengadaan', function () {
        $this->actingAs($this->petugas)->post($this->url, [
            'type' => 'bast',
            'file' => UploadedFile::fake()->create('bast.pdf', 10, 'application/pdf'),
        ]);
        $this->actingAs($this->pejabat)->post($this->url, [
            'type' => 'spk_kontrak',
            'file' => UploadedFile::fake()->create('spk.pdf', 10, 'application/pdf'),
        ]);

        expect($this->procurement->fresh()->documents)->toHaveCount(2);
    });
});

describe('mengunduh berkas', function () {
    beforeEach(function () {
        $this->actingAs($this->petugas)->post($this->url, [
            'type' => 'bast',
            'file' => UploadedFile::fake()->create('bast-asli.pdf', 32, 'application/pdf'),
        ]);

        $this->document = ProcurementDocument::firstOrFail();
    });

    it('mengembalikan berkas dengan nama aslinya', function () {
        $response = $this->actingAs($this->pejabat)
            ->get("{$this->url}/{$this->document->procurement_document_id}/unduh");

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('bast-asli.pdf');
    });

    it('menolak tamu dan peran di luar pengadaan', function () {
        $unduh = "{$this->url}/{$this->document->procurement_document_id}/unduh";

        auth()->logout();
        $this->get($unduh)->assertRedirect('/login');
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())->get($unduh)->assertForbidden();
    });

    it('mengizinkan Admin mengunduh', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get("{$this->url}/{$this->document->procurement_document_id}/unduh")
            ->assertOk();
    });

    it('menganggap berkas milik pengadaan lain sebagai tidak ada', function () {
        $lain = realisasiMenunggu($this->pejabat, $this->petugas);

        $this->actingAs($this->petugas)
            ->get("/pengadaan/{$lain->procurement_id}/dokumen/{$this->document->procurement_document_id}/unduh")
            ->assertNotFound();
    });

    it('memberi pesan ramah ketika berkas hilang dari penyimpanan', function () {
        Storage::disk(DocumentStorage::DISK)->delete($this->document->file_path);

        $this->actingAs($this->petugas)
            ->from("/pengadaan/{$this->procurement->procurement_id}")
            ->get("{$this->url}/{$this->document->procurement_document_id}/unduh")
            ->assertRedirect("/pengadaan/{$this->procurement->procurement_id}")
            ->assertSessionHas('error');
    });
});

describe('menghapus berkas', function () {
    beforeEach(function () {
        $this->actingAs($this->petugas)->post($this->url, [
            'type' => 'bast',
            'file' => UploadedFile::fake()->create('bast.pdf', 16, 'application/pdf'),
        ]);

        $this->document = ProcurementDocument::firstOrFail();
        $this->hapus = "{$this->url}/{$this->document->procurement_document_id}";
    });

    it('menghapus baris beserta berkas fisiknya', function () {
        $path = $this->document->file_path;

        $this->actingAs($this->petugas)->delete($this->hapus)->assertRedirect();

        expect(ProcurementDocument::count())->toBe(0);
        Storage::disk(DocumentStorage::DISK)->assertMissing($path);
    });

    it('menolak pengguna lain yang bukan pengunggahnya', function () {
        $this->actingAs($this->pejabat)
            ->from("/pengadaan/{$this->procurement->procurement_id}")
            ->delete($this->hapus)
            ->assertSessionHas('error');

        expect(ProcurementDocument::count())->toBe(1);
    });

    it('mengizinkan Admin menghapus berkas siapa pun', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->delete($this->hapus)
            ->assertRedirect();

        expect(ProcurementDocument::count())->toBe(0);
    });

    it('mencatat jejak audit penghapusan', function () {
        $this->actingAs($this->petugas)->delete($this->hapus);

        expect(AuditLog::where('activity', 'like', 'Menghapus BAST Bertanda Tangan%')->exists())->toBeTrue();
    });
});

describe('notifikasi BAST belum diunggah', function () {
    /** Notifikasi keadaan yang masih berlaku untuk satu realisasi. */
    $aktif = function (Procurement $procurement): int {
        return Notification::query()
            ->where('type', NotificationType::BastBelumDiunggah)
            ->where('subject_id', $procurement->procurement_id)
            ->aktif()
            ->count();
    };

    it('belum muncul selama jeda pemindaian masih wajar', function () use ($aktif) {
        // Realisasi dari beforeEach diterima hari ini.
        NotificationScanner::periksaSeluruhRealisasi();

        expect($aktif($this->procurement))->toBe(0);
    });

    it('muncul ketika penerimaan sudah lewat berhari-hari tanpa berkas', function () use ($aktif) {
        $lama = realisasiDiterima($this->pejabat, $this->petugas, diterimaHariLalu: 5);

        NotificationScanner::periksaSeluruhRealisasi();

        expect($aktif($lama))->toBeGreaterThan(0);
    });

    it('menutup dirinya sendiri begitu berkas BAST diunggah', function () use ($aktif) {
        $lama = realisasiDiterima($this->pejabat, $this->petugas, diterimaHariLalu: 5);
        NotificationScanner::periksaSeluruhRealisasi();
        expect($aktif($lama))->toBeGreaterThan(0);

        $this->actingAs($this->petugas)->post("/pengadaan/{$lama->procurement_id}/dokumen", [
            'type' => 'bast',
            'file' => UploadedFile::fake()->create('bast.pdf', 10, 'application/pdf'),
        ]);

        expect($aktif($lama))->toBe(0);
    });

    it('tidak ditutup oleh berkas SPK, hanya oleh BAST', function () use ($aktif) {
        $lama = realisasiDiterima($this->pejabat, $this->petugas, diterimaHariLalu: 5);

        $this->actingAs($this->pejabat)->post("/pengadaan/{$lama->procurement_id}/dokumen", [
            'type' => 'spk_kontrak',
            'file' => UploadedFile::fake()->create('spk.pdf', 10, 'application/pdf'),
        ]);

        NotificationScanner::periksaSeluruhRealisasi();

        expect($aktif($lama))->toBeGreaterThan(0);
    });

    it('berlaku lagi ketika berkas BAST dihapus', function () use ($aktif) {
        $lama = realisasiDiterima($this->pejabat, $this->petugas, diterimaHariLalu: 5);

        $this->actingAs($this->petugas)->post("/pengadaan/{$lama->procurement_id}/dokumen", [
            'type' => 'bast',
            'file' => UploadedFile::fake()->create('bast.pdf', 10, 'application/pdf'),
        ]);
        expect($aktif($lama))->toBe(0);

        $document = ProcurementDocument::where('procurement_id', $lama->procurement_id)->firstOrFail();
        $this->actingAs($this->petugas)
            ->delete("/pengadaan/{$lama->procurement_id}/dokumen/{$document->procurement_document_id}");

        expect($aktif($lama))->toBeGreaterThan(0);
    });
});
