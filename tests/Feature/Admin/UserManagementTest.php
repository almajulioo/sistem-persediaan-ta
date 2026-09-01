<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function admin(array $attributes = []): User
{
    return User::factory()->role(UserRole::Admin)->create($attributes);
}

function validPayload(array $overrides = []): array
{
    return array_merge([
        'username' => 'budi.santoso',
        'fullname' => 'Budi Santoso',
        'email' => 'budi@bpk.go.id',
        'role' => UserRole::PengelolaGudang->value,
        'unit' => 'Subbagian Umum dan TU',
        'password' => 'KataSandi#2026',
        'password_confirmation' => 'KataSandi#2026',
        'is_active' => 1,
    ], $overrides);
}

describe('hak akses', function () {
    it('menolak tamu', function () {
        $this->get('/pengguna')->assertRedirect('/login');
    });

    it('menolak peran selain Admin', function () {
        foreach ([UserRole::PengelolaGudang, UserRole::PejabatPengadaan] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/pengguna')
                ->assertForbidden();
        }
    });

    it('menolak non-Admin membuat akun', function () {
        $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
            ->post('/pengguna', validPayload())
            ->assertForbidden();

        expect(User::where('username', 'budi.santoso')->exists())->toBeFalse();
    });

    it('mengizinkan Admin membuka daftar', function () {
        $this->actingAs(admin())->get('/pengguna')->assertOk()->assertSee('Manajemen Pengguna');
    });
});

describe('membuat akun', function () {
    it('menyimpan akun baru dan mencatat audit', function () {
        $actor = admin();

        $this->actingAs($actor)
            ->post('/pengguna', validPayload())
            ->assertRedirect('/pengguna')
            ->assertSessionHas('status');

        $created = User::where('username', 'budi.santoso')->first();

        expect($created)->not->toBeNull()
            ->and($created->role)->toBe(UserRole::PengelolaGudang)
            ->and($created->is_active)->toBeTrue()
            ->and(Hash::check('KataSandi#2026', $created->password))->toBeTrue();

        expect(AuditLog::where('user_id', $actor->user_id)
            ->where('activity', 'Menambah akun pengguna: budi.santoso')
            ->exists())->toBeTrue();
    });

    it('akun baru dapat langsung masuk', function () {
        $this->actingAs(admin())->post('/pengguna', validPayload());

        $this->post('/logout');

        $this->post('/login', ['login' => 'budi.santoso', 'password' => 'KataSandi#2026'])
            ->assertRedirect('/dasbor');

        $this->assertAuthenticated();
    });

    it('menolak nama pengguna dan email ganda', function () {
        User::factory()->create(['username' => 'budi.santoso', 'email' => 'budi@bpk.go.id']);

        $this->actingAs(admin())
            ->post('/pengguna', validPayload())
            ->assertSessionHasErrors(['username', 'email']);
    });

    it('menolak kata sandi lemah dan konfirmasi tidak cocok', function () {
        $this->actingAs(admin())
            ->post('/pengguna', validPayload(['password' => 'abc', 'password_confirmation' => 'abc']))
            ->assertSessionHasErrors('password');

        $this->actingAs(admin())
            ->post('/pengguna', validPayload(['password_confirmation' => 'Berbeda#2026x']))
            ->assertSessionHasErrors('password');
    });

    it('menolak peran di luar daftar', function () {
        $this->actingAs(admin())
            ->post('/pengguna', validPayload(['role' => 'direktur']))
            ->assertSessionHasErrors('role');
    });

    it('dapat membuat akun nonaktif', function () {
        $this->actingAs(admin())->post('/pengguna', validPayload(['is_active' => 0]));

        expect(User::where('username', 'budi.santoso')->first()->is_active)->toBeFalse();
    });
});

describe('mengubah akun', function () {
    it('memperbarui data tanpa mengubah kata sandi bila dikosongkan', function () {
        $target = User::factory()->create(['password' => Hash::make('KataSandi#2026')]);

        $this->actingAs(admin())
            ->put("/pengguna/{$target->user_id}", validPayload([
                'username' => $target->username,
                'email' => $target->email,
                'fullname' => 'Nama Diperbarui',
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertRedirect('/pengguna');

        $target->refresh();

        expect($target->fullname)->toBe('Nama Diperbarui')
            ->and(Hash::check('KataSandi#2026', $target->password))->toBeTrue();
    });

    it('mengganti kata sandi bila diisi', function () {
        $target = User::factory()->create(['password' => Hash::make('KataSandi#2026')]);

        $this->actingAs(admin())->put("/pengguna/{$target->user_id}", validPayload([
            'username' => $target->username,
            'email' => $target->email,
            'password' => 'SandiBaru#2026',
            'password_confirmation' => 'SandiBaru#2026',
        ]));

        expect(Hash::check('SandiBaru#2026', $target->refresh()->password))->toBeTrue();
    });

    it('mencegah Admin mengubah peran akunnya sendiri', function () {
        $actor = admin();

        $this->actingAs($actor)
            ->put("/pengguna/{$actor->user_id}", validPayload([
                'username' => $actor->username,
                'email' => $actor->email,
                'role' => UserRole::PengelolaGudang->value,
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertSessionHasErrors('role');

        expect($actor->refresh()->role)->toBe(UserRole::Admin);
    });

    it('mencegah Admin menonaktifkan akunnya sendiri lewat formulir', function () {
        $actor = admin();

        $this->actingAs($actor)
            ->put("/pengguna/{$actor->user_id}", validPayload([
                'username' => $actor->username,
                'email' => $actor->email,
                'role' => UserRole::Admin->value,
                'is_active' => 0,
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertSessionHasErrors('is_active');

        expect($actor->refresh()->is_active)->toBeTrue();
    });
});

describe('status akun', function () {
    it('mengaktifkan dan menonaktifkan akun lain', function () {
        $actor = admin();
        $target = User::factory()->role(UserRole::PengelolaGudang)->create(['is_active' => true]);

        $this->actingAs($actor)->patch("/pengguna/{$target->user_id}/status");
        expect($target->refresh()->is_active)->toBeFalse();

        $this->actingAs($actor)->patch("/pengguna/{$target->user_id}/status");
        expect($target->refresh()->is_active)->toBeTrue();
    });

    it('menolak menonaktifkan akun sendiri', function () {
        $actor = admin();

        $this->actingAs($actor)
            ->patch("/pengguna/{$actor->user_id}/status")
            ->assertSessionHas('error');

        expect($actor->refresh()->is_active)->toBeTrue();
    });

    it('mengaktifkan kembali akun yang dinonaktifkan', function () {
        $actor = admin();
        $other = admin(['is_active' => false]);

        $this->actingAs($actor)
            ->patch("/pengguna/{$other->user_id}/status")
            ->assertSessionHas('status');

        expect($other->refresh()->is_active)->toBeTrue();
    });

    it('akun yang dinonaktifkan langsung kehilangan akses', function () {
        $target = User::factory()->role(UserRole::PengelolaGudang)->create(['is_active' => true]);

        $this->actingAs(admin())->patch("/pengguna/{$target->user_id}/status");

        $this->actingAs($target->refresh())->get('/dasbor')->assertRedirect('/login');
    });
});

describe('menghapus akun', function () {
    it('menghapus akun tanpa data transaksi', function () {
        $actor = admin();
        $target = User::factory()->role(UserRole::PengelolaGudang)->create();

        $this->actingAs($actor)
            ->delete("/pengguna/{$target->user_id}")
            ->assertRedirect('/pengguna')
            ->assertSessionHas('status');

        expect(User::find($target->user_id))->toBeNull();
    });

    it('menolak menghapus akun sendiri', function () {
        $actor = admin();

        $this->actingAs($actor)
            ->delete("/pengguna/{$actor->user_id}")
            ->assertSessionHas('error');

        expect(User::find($actor->user_id))->not->toBeNull();
    });

    it('menolak menghapus akun yang punya jejak audit', function () {
        $actor = admin();
        $target = User::factory()->role(UserRole::PengelolaGudang)->create();

        AuditLog::create([
            'user_id' => $target->user_id,
            'activity' => 'Masuk ke sistem',
            'timestamp' => now(),
        ]);

        $this->actingAs($actor)
            ->delete("/pengguna/{$target->user_id}")
            ->assertSessionHas('error');

        expect(User::find($target->user_id))->not->toBeNull();
    });
});

describe('penyaringan daftar', function () {
    it('menyaring berdasarkan kata kunci, peran, dan status', function () {
        $actor = admin(['fullname' => 'Admin Utama']);
        User::factory()->role(UserRole::PengelolaGudang)->create(['fullname' => 'Siti Gudang', 'is_active' => true]);
        User::factory()->role(UserRole::PejabatPengadaan)->create(['fullname' => 'Rudi Pengadaan', 'is_active' => false]);

        $this->actingAs($actor)->get('/pengguna?q=Siti')
            ->assertSee('Siti Gudang')
            ->assertDontSee('Rudi Pengadaan');

        $this->actingAs($actor)->get('/pengguna?role='.UserRole::PejabatPengadaan->value)
            ->assertSee('Rudi Pengadaan')
            ->assertDontSee('Siti Gudang');

        $this->actingAs($actor)->get('/pengguna?status=nonaktif')
            ->assertSee('Rudi Pengadaan')
            ->assertDontSee('Siti Gudang');
    });
});
