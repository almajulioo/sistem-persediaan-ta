<?php

use App\Models\User;

test('halaman utama mengarahkan tamu ke halaman masuk', function () {
    $this->get('/')->assertRedirect('/login');
});

test('halaman utama mengarahkan pengguna ke dasbor', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect('/dasbor');
});
