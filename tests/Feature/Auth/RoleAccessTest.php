<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

it('menandai peran pengguna dengan benar', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->hasRole(UserRole::PengelolaGudang))->toBeFalse()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->hasRole('peran_tidak_dikenal'))->toBeFalse();
});

it('meloloskan Admin pada seluruh gate', function () {
    $admin = User::factory()->role(UserRole::Admin)->create();

    // Termasuk `setujui-pengadaan`. Konsekuensi yang disengaja dari ADR 0030:
    // pemisahan staf TI dari pemberi persetujuan bersifat organisasional,
    // bukan ditegakkan sistem.
    expect($admin->can('kelola-pengguna'))->toBeTrue()
        ->and($admin->can('kelola-gudang'))->toBeTrue()
        ->and($admin->can('kelola-pengadaan'))->toBeTrue()
        ->and($admin->can('setujui-pengadaan'))->toBeTrue()
        ->and($admin->can('akses-pengadaan'))->toBeTrue();
});

it('membatasi gate sesuai peran non-admin', function () {
    $gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $pengadaan = User::factory()->role(UserRole::PejabatPengadaan)->create();
    $kasubbag = User::factory()->role(UserRole::KasubbagUmumTi)->create();

    expect($gudang->can('kelola-gudang'))->toBeTrue()
        ->and($gudang->can('kelola-pengguna'))->toBeFalse()
        ->and($pengadaan->can('kelola-pengadaan'))->toBeTrue()
        ->and($pengadaan->can('kelola-gudang'))->toBeFalse();

    // Kasubbag Umum dan TI hanya memutuskan; ia tidak mengusulkan, tidak
    // merealisasi, dan tidak menerima barang (ADR 0016).
    expect($kasubbag->can('setujui-pengadaan'))->toBeTrue()
        ->and($kasubbag->can('akses-pengadaan'))->toBeTrue()
        ->and($kasubbag->can('kelola-pengadaan'))->toBeFalse()
        ->and($kasubbag->can('kelola-gudang'))->toBeFalse()
        ->and($kasubbag->can('kelola-pengguna'))->toBeFalse();

    // Sebaliknya, tak satu pun peran pelaksana boleh menyetujui dokumennya
    // sendiri maupun dokumen rekannya.
    expect($pengadaan->can('setujui-pengadaan'))->toBeFalse()
        ->and($gudang->can('setujui-pengadaan'))->toBeFalse();
});

it('menyaring menu samping sesuai peran', function () {
    $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
        ->get('/dasbor')
        ->assertOk()
        ->assertSee('Pengadaan')
        ->assertDontSee('Manajemen Pengguna')
        ->assertDontSee('Stock Opname');
});

it('menampilkan grup menu Persetujuan hanya untuk pemberi persetujuan', function () {
    $this->actingAs(User::factory()->role(UserRole::KasubbagUmumTi)->create())
        ->get('/dasbor')
        ->assertOk()
        ->assertSee('Persetujuan')
        // Tautannya membawa penyaring status, bukan daftar realisasi polos.
        // Dicocokkan dengan `href="/…` relatif khas sidebar: dasbor juga
        // menautkan penyaring yang sama (alur pengadaan), tetapi lewat route()
        // yang absolut, dan Pengelola Gudang memang boleh melihatnya di sana.
        ->assertSee('href="/pengadaan?status=menunggu_persetujuan"', false)
        ->assertDontSee('Manajemen Pengguna');

    $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
        ->get('/dasbor')
        ->assertOk()
        ->assertDontSee('href="/pengadaan?status=menunggu_persetujuan"', false);
});

it('menampilkan menu administrasi hanya untuk Admin', function () {
    $this->actingAs(User::factory()->role(UserRole::Admin)->create())
        ->get('/dasbor')
        ->assertOk()
        ->assertSee('Manajemen Pengguna')
        ->assertSee('Log Aktivitas');
});

it('menolak akses rute dengan middleware role yang tidak cocok', function () {
    Route::middleware(['web', 'auth', 'role:admin'])->get('/uji-role', fn () => 'ok');

    $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
        ->get('/uji-role')
        ->assertForbidden();

    $this->actingAs(User::factory()->role(UserRole::Admin)->create())
        ->get('/uji-role')
        ->assertOk();
});
