<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function makeUser(array $attributes = []): User
{
    return User::factory()->create($attributes + [
        'username' => 'gudang01',
        'email' => 'gudang01@bpk.go.id',
        'password' => Hash::make('KataSandi#2026'),
        'role' => UserRole::PengelolaGudang,
    ]);
}

it('menampilkan halaman masuk untuk tamu', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Masuk');
});

it('mengarahkan tamu ke halaman masuk saat membuka dasbor', function () {
    $this->get('/dasbor')->assertRedirect('/login');
});

it('mengizinkan masuk memakai nama pengguna', function () {
    $user = makeUser();

    $this->post('/login', [
        'login' => 'gudang01',
        'password' => 'KataSandi#2026',
    ])->assertRedirect('/dasbor');

    $this->assertAuthenticatedAs($user);
});

it('mengizinkan masuk memakai email', function () {
    makeUser();

    $this->post('/login', [
        'login' => 'gudang01@bpk.go.id',
        'password' => 'KataSandi#2026',
    ])->assertRedirect('/dasbor');

    $this->assertAuthenticated();
});

it('menolak kata sandi yang salah', function () {
    makeUser();

    $this->post('/login', [
        'login' => 'gudang01',
        'password' => 'salah',
    ])->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('menolak akun yang dinonaktifkan', function () {
    makeUser(['is_active' => false]);

    $this->post('/login', [
        'login' => 'gudang01',
        'password' => 'KataSandi#2026',
    ])->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('mengunci sementara setelah lima percobaan gagal', function () {
    makeUser();

    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['login' => 'gudang01', 'password' => 'salah']);
    }

    $response = $this->post('/login', [
        'login' => 'gudang01',
        'password' => 'KataSandi#2026',
    ]);

    $response->assertSessionHasErrors('login');
    expect(session('errors')->first('login'))->toContain('Terlalu banyak percobaan');
    $this->assertGuest();
});

it('mencatat waktu login terakhir dan jejak audit', function () {
    $user = makeUser();

    $this->post('/login', [
        'login' => 'gudang01',
        'password' => 'KataSandi#2026',
    ]);

    expect($user->refresh()->last_login_at)->not->toBeNull();
    expect(AuditLog::where('user_id', $user->user_id)->where('activity', 'Masuk ke sistem')->exists())->toBeTrue();
});

it('mengeluarkan pengguna dan menghancurkan sesi', function () {
    $user = makeUser();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest();
    expect(AuditLog::where('user_id', $user->user_id)->where('activity', 'Keluar dari sistem')->exists())->toBeTrue();
});

it('mengeluarkan pengguna yang dinonaktifkan di tengah sesi', function () {
    $user = makeUser();

    $this->actingAs($user)->get('/dasbor')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get('/dasbor')->assertRedirect('/login');
});

it('mengarahkan pengguna yang sudah masuk menjauh dari halaman masuk', function () {
    $this->actingAs(makeUser())
        ->get('/login')
        ->assertRedirect('/dasbor');
});
