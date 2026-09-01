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

    expect($admin->can('kelola-pengguna'))->toBeTrue()
        ->and($admin->can('kelola-gudang'))->toBeTrue()
        ->and($admin->can('kelola-pengadaan'))->toBeTrue();
});

it('membatasi gate sesuai peran non-admin', function () {
    $gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $pengadaan = User::factory()->role(UserRole::PejabatPengadaan)->create();

    expect($gudang->can('kelola-gudang'))->toBeTrue()
        ->and($gudang->can('kelola-pengguna'))->toBeFalse()
        ->and($pengadaan->can('kelola-pengadaan'))->toBeTrue()
        ->and($pengadaan->can('kelola-gudang'))->toBeFalse();
});

it('menyaring menu samping sesuai peran', function () {
    $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
        ->get('/dasbor')
        ->assertOk()
        ->assertSee('Pengadaan')
        ->assertDontSee('Manajemen Pengguna')
        ->assertDontSee('Stock Opname');
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
