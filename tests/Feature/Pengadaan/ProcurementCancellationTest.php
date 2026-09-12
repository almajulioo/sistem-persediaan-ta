<?php

use App\Enums\AlasanPembatalan;
use App\Enums\NotificationType;
use App\Enums\ProcurementRequestStatus;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Procurement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\NotificationScanner;

// Nama pembantu di berkas Pest bersifat global: `buatUsulan`, `setujuiUsulan`,
// `buatRealisasi`, `realisasiMenunggu`, dan `realisasiDiterima` dipakai ulang
// dari berkas uji Tahap 2, 3, dan 4.

/**
 * Muatan pembatalan yang sah.
 *
 * @return array<string, string>
 */
function pembatalanPayload(array $overrides = []): array
{
    return array_merge([
        'cancel_reason' => AlasanPembatalan::PenyediaGagalKirim->value,
        'cancel_note' => 'Penyedia menyatakan stok kosong dan tidak sanggup mengirim sampai akhir bulan.',
    ], $overrides);
}

/** Notifikasi yang masih berlaku untuk satu subjek. */
function notifikasiAktifUntuk(NotificationType $type, string $subjectId): int
{
    return Notification::query()
        ->where('type', $type)
        ->where('subject_id', $subjectId)
        ->aktif()
        ->count();
}

beforeEach(function () {
    $this->petugas = pengelolaGudang();
    $this->pejabat = pejabatPengadaan();
    $this->procurement = realisasiMenunggu($this->pejabat, $this->petugas);
    $this->url = "/pengadaan/{$this->procurement->procurement_id}/batal";
});

describe('hak akses pembatalan', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        auth()->logout();

        $this->patch($this->url, pembatalanPayload())->assertRedirect('/login');
    });

    it('menolak Pengelola Gudang', function () {
        // Membatalkan adalah keputusan Pejabat Pengadaan atas dokumennya
        // sendiri, sejajar pemisahan tugas ADR 0016.
        $this->actingAs($this->petugas)
            ->patch($this->url, pembatalanPayload())
            ->assertForbidden();

        expect($this->procurement->fresh()->isMenungguPenerimaan())->toBeTrue();
    });

    it('menolak peran di luar pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->patch($this->url, pembatalanPayload())
            ->assertForbidden();
    });

    it('mengizinkan Pejabat Pengadaan', function () {
        $this->actingAs($this->pejabat)
            ->patch($this->url, pembatalanPayload())
            ->assertRedirect("/pengadaan/{$this->procurement->procurement_id}");

        expect($this->procurement->fresh()->isDibatalkan())->toBeTrue();
    });

    it('mengizinkan Admin', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->patch($this->url, pembatalanPayload())
            ->assertRedirect();

        expect($this->procurement->fresh()->isDibatalkan())->toBeTrue();
    });
});

describe('membatalkan realisasi', function () {
    it('menyimpan alasan, keterangan, pembatal, dan waktunya', function () {
        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        $procurement = $this->procurement->fresh();

        expect($procurement->status)->toBe(ProcurementStatus::Dibatalkan)
            ->and($procurement->cancel_reason)->toBe(AlasanPembatalan::PenyediaGagalKirim)
            ->and($procurement->cancel_note)->toContain('stok kosong')
            ->and($procurement->cancelled_by)->toBe($this->pejabat->user_id)
            ->and($procurement->cancelled_at)->not->toBeNull();
    });

    it('mencatat jejak audit', function () {
        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        expect(AuditLog::where('activity', 'like', 'Membatalkan Realisasi%')->exists())->toBeTrue();
    });

    it('mewajibkan keterangan diisi', function () {
        $this->actingAs($this->pejabat)
            ->patch($this->url, pembatalanPayload(['cancel_note' => '']))
            ->assertSessionHasErrors('cancel_note');

        expect($this->procurement->fresh()->isMenungguPenerimaan())->toBeTrue();
    });

    it('menolak alasan yang tidak dikenal', function () {
        $this->actingAs($this->pejabat)
            ->patch($this->url, pembatalanPayload(['cancel_reason' => 'penyedia_kabur']))
            ->assertSessionHasErrors('cancel_reason');
    });

    it('menolak membatalkan realisasi yang sudah diterima', function () {
        $diterima = realisasiDiterima($this->pejabat, $this->petugas);

        $this->actingAs($this->pejabat)
            ->from("/pengadaan/{$diterima->procurement_id}")
            ->patch("/pengadaan/{$diterima->procurement_id}/batal", pembatalanPayload())
            ->assertSessionHas('error');

        expect($diterima->fresh()->isDiterima())->toBeTrue();
    });

    it('menolak pembatalan ganda', function () {
        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        $this->actingAs($this->pejabat)
            ->from("/pengadaan/{$this->procurement->procurement_id}")
            ->patch($this->url, pembatalanPayload(['cancel_note' => 'Percobaan kedua.']))
            ->assertSessionHas('error');

        expect($this->procurement->fresh()->cancel_note)->toContain('stok kosong');
    });

    it('tidak menyentuh stok sama sekali', function () {
        // Pembatalan hanya mungkin sebelum penerimaan, jadi tidak ada satu pun
        // mutasi yang perlu dibalik — inilah yang membuat fitur ini aman.
        $item = Item::findOrFail($this->procurement->details->first()->item_id);
        $stokSebelum = $item->stock_current;

        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        expect($item->fresh()->stock_current)->toBe($stokSebelum)
            ->and($item->fresh()->stockMutations()->count())->toBe(0);
    });

    it('tetap menolak cetak BAST setelah dibatalkan', function () {
        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        $this->actingAs($this->pejabat)
            ->get("/pengadaan/{$this->procurement->procurement_id}/bast")
            ->assertRedirect("/pengadaan/{$this->procurement->procurement_id}")
            ->assertSessionHas('error');
    });
});

describe('usulan setelah realisasinya dibatalkan', function () {
    it('status usulannya tetap DisetujuiSelesai, tidak berbalik menjadi menunggu realisasi', function () {
        // Definisi ADR 0028: "selesai" berarti sudah ada dokumen realisasi
        // untuk usulan ini, terlepas dari nasibnya — dibatalkan bukan
        // pengecualian.
        $usulan = $this->procurement->procurementRequest;
        expect($usulan->fresh()->status)->toBe(ProcurementRequestStatus::DisetujuiSelesai);

        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        expect($usulan->fresh()->status)->toBe(ProcurementRequestStatus::DisetujuiSelesai);
    });

    it('tidak dapat direalisasikan ulang', function () {
        $usulan = $this->procurement->procurementRequest;

        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        // Formulir realisasi menolak membukanya kembali...
        $this->actingAs($this->pejabat)
            ->get("/pengadaan/create?procurement_request_id={$usulan->procurement_request_id}")
            ->assertRedirect("/usulan-pengadaan/{$usulan->procurement_request_id}")
            ->assertSessionHas('error');

        // ...dan kiriman langsung ke store pun ditolak indeks uniknya.
        $this->actingAs($this->pejabat)
            ->post('/pengadaan', realisasiPayload($usulan->fresh('details'), Supplier::factory()->create()))
            ->assertSessionHasErrors('procurement_request_id');

        expect(Procurement::where('procurement_request_id', $usulan->procurement_request_id)->count())->toBe(1);
    });

    it('menjelaskan di layar mengapa tombol Realisasikan tidak ada', function () {
        $usulan = $this->procurement->procurementRequest;

        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        $this->actingAs($this->pejabat)
            ->get("/usulan-pengadaan/{$usulan->procurement_request_id}")
            ->assertOk()
            // Frasa dipilih yang tidak terpotong pergantian baris Blade —
            // assertSee membandingkan substring mentah, spasi indentasi ikut.
            ->assertSee('Realisasi usulan ini dibatalkan')
            ->assertSee('direalisasikan ulang')
            ->assertSee('mengajukan usulan');
    });
});

describe('notifikasi pembatalan', function () {
    it('menutup RealisasiMenungguPenerimaan dan memberi tahu gudang', function () {
        NotificationScanner::periksaRealisasi($this->procurement);
        expect(notifikasiAktifUntuk(
            NotificationType::RealisasiMenungguPenerimaan,
            $this->procurement->procurement_id,
        ))->toBeGreaterThan(0);

        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        expect(notifikasiAktifUntuk(
            NotificationType::RealisasiMenungguPenerimaan,
            $this->procurement->procurement_id,
        ))->toBe(0);

        // Menghilang diam-diam tidak cukup: gudang sudah diberi tahu untuk
        // menunggu barang, jadi ia harus tahu barangnya tidak jadi datang.
        $kabar = Notification::query()
            ->where('type', NotificationType::RealisasiDibatalkan)
            ->where('user_id', $this->petugas->user_id)
            ->first();

        expect($kabar)->not->toBeNull()
            ->and($kabar->body)->toContain('Penyedia Gagal Kirim')
            ->and($kabar->body)->toContain('stok kosong');
    });

    it('tidak memberi tahu pelakunya sendiri', function () {
        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        expect(Notification::query()
            ->where('type', NotificationType::RealisasiDibatalkan)
            ->where('user_id', $this->pejabat->user_id)
            ->count())->toBe(0);
    });
});

describe('notifikasi usulan disetujui belum direalisasi', function () {
    it('belum muncul selama jedanya masih wajar', function () {
        $usulan = setujuiUsulan($this->pejabat, buatUsulan($this->petugas, [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));

        NotificationScanner::periksaSeluruhUsulan();

        expect(notifikasiAktifUntuk(
            NotificationType::UsulanBelumDirealisasi,
            $usulan->procurement_request_id,
        ))->toBe(0);
    });

    it('muncul setelah usulan disetujui menganggur berhari-hari', function () {
        $usulan = setujuiUsulan($this->pejabat, buatUsulan($this->petugas, [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));

        $this->travel(8)->days();
        NotificationScanner::periksaSeluruhUsulan();

        expect(notifikasiAktifUntuk(
            NotificationType::UsulanBelumDirealisasi,
            $usulan->procurement_request_id,
        ))->toBeGreaterThan(0);
    });

    it('menutup dirinya sendiri seketika begitu usulannya direalisasi', function () {
        $usulan = setujuiUsulan($this->pejabat, buatUsulan($this->petugas, [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));

        $this->travel(8)->days();
        NotificationScanner::periksaSeluruhUsulan();
        expect(notifikasiAktifUntuk(
            NotificationType::UsulanBelumDirealisasi,
            $usulan->procurement_request_id,
        ))->toBeGreaterThan(0);

        // Tanpa sapuan terjadwal sesudahnya: pembuatan realisasi sendiri yang
        // harus menutupnya, supaya tagihannya tidak bertahan berjam-jam
        // setelah orang sudah menindaklanjutinya.
        buatRealisasi($this->pejabat, $usulan->fresh('details'), Supplier::factory()->create());

        expect(notifikasiAktifUntuk(
            NotificationType::UsulanBelumDirealisasi,
            $usulan->procurement_request_id,
        ))->toBe(0);
    });

    it('tidak menagih usulan yang realisasinya sudah dibatalkan', function () {
        // Menurut ADR 0024 riwayat usulan itu memang sudah selesai; menagihnya
        // berarti menyuruh orang melakukan sesuatu yang tidak mungkin lagi.
        $usulan = $this->procurement->procurementRequest;
        $this->actingAs($this->pejabat)->patch($this->url, pembatalanPayload());

        $this->travel(8)->days();
        NotificationScanner::periksaSeluruhUsulan();

        expect(notifikasiAktifUntuk(
            NotificationType::UsulanBelumDirealisasi,
            $usulan->procurement_request_id,
        ))->toBe(0);
    });

    it('tidak menagih usulan yang ditolak', function () {
        $usulan = buatUsulan($this->petugas, [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]);
        $this->actingAs($this->pejabat)->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/tolak");

        $this->travel(8)->days();
        NotificationScanner::periksaSeluruhUsulan();

        expect(notifikasiAktifUntuk(
            NotificationType::UsulanBelumDirealisasi,
            $usulan->procurement_request_id,
        ))->toBe(0);
    });
});
