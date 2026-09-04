<?php

use App\Enums\MutationType;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationScanner;
use App\Support\Notifier;
use App\Support\StockLedger;

// Nama fungsi pembantu di Pest bersifat global lintas berkas; `gudang()` dan
// `petugasGudang()` sudah dipakai berkas uji lain.
function petugasNotifikasi(array $attributes = []): User
{
    return User::factory()->role(UserRole::PengelolaGudang)->create($attributes);
}

function adminNotifikasi(array $attributes = []): User
{
    return User::factory()->role(UserRole::Admin)->create($attributes);
}

function hitungNotifikasi(NotificationType $type, ?User $user = null): int
{
    return Notification::query()
        ->where('type', $type)
        ->when($user !== null, fn ($query) => $query->where('user_id', $user->user_id))
        ->count();
}

describe('hak akses', function () {
    // Halaman ini sengaja tidak memakai gate peran: setiap pengguna berhak atas
    // notifikasinya sendiri. Karena itu matriks peran yang biasa dipakai modul
    // gudang diganti — ketiga peran boleh masuk, dan yang dijaga adalah
    // kepemilikan tiap baris (diuji di blok "kepemilikan").
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/notifikasi')->assertRedirect('/login');
    });

    it('mengizinkan Pengelola Gudang', function () {
        $this->actingAs(petugasNotifikasi())->get('/notifikasi')->assertOk()->assertSee('Notifikasi');
    });

    it('mengizinkan Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get('/notifikasi')->assertOk();
    });

    it('mengizinkan Admin', function () {
        $this->actingAs(adminNotifikasi())->get('/notifikasi')->assertOk();
    });
});

describe('keadaan stok', function () {
    it('memberi tahu Pengelola Gudang dan Admin saat stok habis', function () {
        $petugas = petugasNotifikasi();
        $admin = adminNotifikasi();
        $item = Item::factory()->habis()->create(['item_name' => 'Kertas HVS A4']);

        NotificationScanner::periksaBarang($item);

        expect(hitungNotifikasi(NotificationType::StokHabis))->toBe(2)
            ->and(hitungNotifikasi(NotificationType::StokHabis, $petugas))->toBe(1)
            ->and(hitungNotifikasi(NotificationType::StokHabis, $admin))->toBe(1);

        $notification = Notification::query()->where('user_id', $petugas->user_id)->first();

        expect($notification->title)->toContain('Kertas HVS A4')
            ->and($notification->body)->toContain('usulan pengadaan')
            ->and($notification->masihAktif())->toBeTrue()
            ->and($notification->sudahDibaca())->toBeFalse();
    });

    it('tidak menumpuk baris meski diperiksa berkali-kali', function () {
        petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        NotificationScanner::periksaBarang($item);
        NotificationScanner::periksaBarang($item);
        NotificationScanner::periksaBarang($item);

        expect(hitungNotifikasi(NotificationType::StokHabis))->toBe(1);
    });

    it('menutup notifikasi sendiri begitu stok terisi kembali', function () {
        $petugas = petugasNotifikasi();

        // Batas atas dimatikan (0 = tanpa batas) supaya pengisian ulang tidak
        // justru memicu peringatan stok berlebih dan mengaburkan yang diuji.
        $item = Item::factory()->create(['stock_current' => 0, 'stock_min' => 5, 'stock_max' => 0]);

        NotificationScanner::periksaBarang($item);
        expect(Notification::query()->aktif()->count())->toBe(1);

        StockLedger::record(
            item: $item,
            type: MutationType::HibahMasuk,
            amount: 100,
            recordedBy: $petugas,
        );

        NotificationScanner::periksaBarang($item);

        $notification = Notification::query()->where('type', NotificationType::StokHabis)->firstOrFail();

        expect(Notification::query()->aktif()->count())->toBe(0)
            ->and($notification->resolved_at)->not->toBeNull()
            // Barisnya ditutup, bukan dihapus.
            ->and(hitungNotifikasi(NotificationType::StokHabis))->toBe(1);
    });

    it('menghidupkan kembali baris yang sama saat keadaan kambuh', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->create(['stock_current' => 0, 'stock_min' => 5, 'stock_max' => 0]);

        NotificationScanner::periksaBarang($item);
        Notifier::tandaiSemuaDibaca($petugas);

        // Terisi lalu habis lagi.
        StockLedger::record(item: $item, type: MutationType::HibahMasuk, amount: 100, recordedBy: $petugas);
        NotificationScanner::periksaBarang($item);

        StockLedger::record(item: $item, type: MutationType::BarangRusak, amount: 100, recordedBy: $petugas);
        NotificationScanner::periksaBarang($item);

        $notification = Notification::query()->where('type', NotificationType::StokHabis)->firstOrFail();

        expect(hitungNotifikasi(NotificationType::StokHabis))->toBe(1)
            ->and($notification->masihAktif())->toBeTrue()
            // Ditagih ulang: keadaan yang kambuh bukan kabar lama.
            ->and($notification->sudahDibaca())->toBeFalse();
    });

    it('memisahkan stok habis dari stok menipis', function () {
        // Satu petugas saja: tiap pemanggilan factory menambah penerima baru.
        $petugas = petugasNotifikasi();
        $item = Item::factory()->create(['stock_current' => 10, 'stock_min' => 3, 'stock_max' => 0]);

        NotificationScanner::periksaBarang($item);
        expect(Notification::query()->aktif()->count())->toBe(0);

        StockLedger::record(item: $item, type: MutationType::BarangRusak, amount: 7, recordedBy: $petugas);
        NotificationScanner::periksaBarang($item);

        expect(hitungNotifikasi(NotificationType::StokMenipis))->toBe(1)
            ->and(hitungNotifikasi(NotificationType::StokHabis))->toBe(0);

        StockLedger::record(item: $item, type: MutationType::BarangRusak, amount: 3, recordedBy: $petugas);
        NotificationScanner::periksaBarang($item);

        expect(Notification::query()->where('type', NotificationType::StokHabis)->aktif()->count())->toBe(1)
            ->and(Notification::query()->where('type', NotificationType::StokMenipis)->aktif()->count())->toBe(0);
    });

    it('menutup peringatan stok saat barang diarsipkan', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        NotificationScanner::periksaBarang($item);
        expect(Notification::query()->aktif()->count())->toBe(1);

        $this->actingAs($petugas)
            ->patch("/barang/{$item->item_id}/status")
            ->assertSessionHas('status');

        expect(Notification::query()->aktif()->count())->toBe(0);
    });
});

describe('pemicu dari alur aplikasi', function () {
    it('memunculkan peringatan setelah mutasi manual menghabiskan stok', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->create(['stock_current' => 5, 'stock_min' => 1, 'stock_max' => 0]);

        $this->actingAs($petugas)->post('/mutasi', [
            'item_id' => $item->item_id,
            'mutation_type' => MutationType::BarangRusak->value,
            'amount' => 5,
            'mutation_date' => now()->format('Y-m-d'),
            'notes' => 'Rusak terkena air saat gudang bocor.',
        ])->assertRedirect('/mutasi');

        expect(hitungNotifikasi(NotificationType::StokHabis, $petugas))->toBe(1);
    });

    it('melaporkan mutasi manual sensitif kepada Admin tanpa memberi tahu pelakunya', function () {
        $petugas = petugasNotifikasi();
        $admin = adminNotifikasi();
        $item = Item::factory()->create(['stock_current' => 50, 'stock_min' => 1, 'stock_max' => 0]);

        $this->actingAs($petugas)->post('/mutasi', [
            'item_id' => $item->item_id,
            'mutation_type' => MutationType::BarangHilang->value,
            'amount' => 10,
            'mutation_date' => now()->format('Y-m-d'),
            'notes' => 'Tidak ditemukan saat penataan ulang rak.',
        ])->assertRedirect('/mutasi');

        expect(hitungNotifikasi(NotificationType::MutasiManualSensitif, $admin))->toBe(1)
            // Pelakunya baru saja melakukannya sendiri.
            ->and(hitungNotifikasi(NotificationType::MutasiManualSensitif, $petugas))->toBe(0);
    });

    it('tidak melaporkan mutasi manual yang menambah stok', function () {
        $petugas = petugasNotifikasi();
        adminNotifikasi();
        $item = Item::factory()->create(['stock_current' => 50, 'stock_min' => 1, 'stock_max' => 0]);

        $this->actingAs($petugas)->post('/mutasi', [
            'item_id' => $item->item_id,
            'mutation_type' => MutationType::HibahMasuk->value,
            'amount' => 10,
            'mutation_date' => now()->format('Y-m-d'),
            'notes' => 'Hibah dari unit lain.',
        ])->assertRedirect('/mutasi');

        expect(hitungNotifikasi(NotificationType::MutasiManualSensitif))->toBe(0);
    });
});

describe('membaca notifikasi', function () {
    it('menandai dibaca lalu mengantar ke tujuan tindak lanjut', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        NotificationScanner::periksaBarang($item);
        $notification = Notification::query()->where('user_id', $petugas->user_id)->firstOrFail();

        $this->actingAs($petugas)
            ->patch("/notifikasi/{$notification->notification_id}/baca")
            ->assertRedirect("/barang/{$item->item_id}");

        expect($notification->fresh()->sudahDibaca())->toBeTrue();
    });

    it('menandai seluruh notifikasi dibaca', function () {
        $petugas = petugasNotifikasi();
        Item::factory()->count(3)->habis()->create()
            ->each(fn (Item $item) => NotificationScanner::periksaBarang($item));

        expect(Notification::query()->untuk($petugas)->belumDibaca()->count())->toBe(3);

        $this->actingAs($petugas)
            ->post('/notifikasi/baca-semua')
            ->assertSessionHas('status');

        expect(Notification::query()->untuk($petugas)->belumDibaca()->count())->toBe(0);
    });
});

describe('kepemilikan', function () {
    it('menolak membaca notifikasi milik pengguna lain', function () {
        $pemilik = petugasNotifikasi();
        $orangLain = petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        NotificationScanner::periksaBarang($item);
        $notification = Notification::query()->where('user_id', $pemilik->user_id)->firstOrFail();

        $this->actingAs($orangLain)
            ->patch("/notifikasi/{$notification->notification_id}/baca")
            ->assertNotFound();

        expect($notification->fresh()->sudahDibaca())->toBeFalse();
    });

    it('hanya menampilkan notifikasi milik sendiri', function () {
        $pemilik = petugasNotifikasi(['fullname' => 'Pemilik Notifikasi']);
        $orangLain = petugasNotifikasi();
        Item::factory()->habis()->create(['item_name' => 'Tinta Printer Hitam']);

        NotificationScanner::periksaSeluruhBarang();
        Notification::query()->where('user_id', '!=', $pemilik->user_id)->delete();

        $this->actingAs($pemilik)->get('/notifikasi')->assertSee('Tinta Printer Hitam');
        $this->actingAs($orangLain)->get('/notifikasi')->assertDontSee('Tinta Printer Hitam');
    });
});

describe('perintah terjadwal', function () {
    it('berjalan dan bersifat idempoten', function () {
        petugasNotifikasi();
        Item::factory()->count(2)->habis()->create();

        $this->artisan('notifikasi:periksa')->assertSuccessful();
        $pertama = hitungNotifikasi(NotificationType::StokHabis);

        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect($pertama)->toBe(2)
            ->and(hitungNotifikasi(NotificationType::StokHabis))->toBe(2);
    });

    it('menutup keadaan yang sudah pulih pada pemeriksaan berikutnya', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        $this->artisan('notifikasi:periksa')->assertSuccessful();
        expect(Notification::query()->where('type', NotificationType::StokHabis)->aktif()->count())->toBe(1);

        StockLedger::record(item: $item, type: MutationType::HibahMasuk, amount: 500, recordedBy: $petugas);

        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect(Notification::query()->where('type', NotificationType::StokHabis)->aktif()->count())->toBe(0);
    });
});
