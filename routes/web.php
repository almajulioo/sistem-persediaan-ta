<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Gudang\ItemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute Tamu
|--------------------------------------------------------------------------
| Sistem ini tertutup: tidak ada pendaftaran mandiri. Akun dibuat oleh Admin.
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Rute Terautentikasi
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/dasbor');
    Route::get('/dasbor', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profil', function () {
        return view('pages.profile', ['title' => 'Profil']);
    })->name('profile');

    /*
    |----------------------------------------------------------------------
    | Manajemen gudang (Pengelola Gudang, dan Admin)
    |----------------------------------------------------------------------
    | Memakai gate `kelola-gudang`, bukan middleware `role:`, karena gate sudah
    | meloloskan Admin lewat Gate::before sedangkan `role:` memeriksa peran
    | secara harfiah dan akan menolak Admin.
    */
    Route::middleware('can:kelola-gudang')->group(function () {
        Route::resource('barang', ItemController::class)
            ->parameters(['barang' => 'item'])
            ->names('items');

        Route::patch('barang/{item}/status', [ItemController::class, 'toggleStatus'])
            ->name('items.toggle-status');
    });

    /*
    |----------------------------------------------------------------------
    | Administrasi sistem (khusus Admin)
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::resource('pengguna', UserController::class)
            ->parameters(['pengguna' => 'user'])
            ->names('users')
            ->except(['show']);

        Route::patch('pengguna/{user}/status', [UserController::class, 'toggleStatus'])
            ->name('users.toggle-status');
    });

    /*
    |----------------------------------------------------------------------
    | Halaman referensi komponen TailAdmin
    |----------------------------------------------------------------------
    | Sementara dipertahankan sebagai contoh UI selama modul dikembangkan.
    */
    Route::get('/calendar', fn () => view('pages.calender', ['title' => 'Kalender']))->name('calendar');
    Route::get('/form-elements', fn () => view('pages.form.form-elements', ['title' => 'Form Elements']))->name('form-elements');
    Route::get('/basic-tables', fn () => view('pages.tables.basic-tables', ['title' => 'Basic Tables']))->name('basic-tables');
    Route::get('/blank', fn () => view('pages.blank', ['title' => 'Blank']))->name('blank');
    Route::get('/line-chart', fn () => view('pages.chart.line-chart', ['title' => 'Line Chart']))->name('line-chart');
    Route::get('/bar-chart', fn () => view('pages.chart.bar-chart', ['title' => 'Bar Chart']))->name('bar-chart');
    Route::get('/alerts', fn () => view('pages.ui-elements.alerts', ['title' => 'Alerts']))->name('alerts');
    Route::get('/avatars', fn () => view('pages.ui-elements.avatars', ['title' => 'Avatars']))->name('avatars');
    Route::get('/badge', fn () => view('pages.ui-elements.badges', ['title' => 'Badges']))->name('badges');
    Route::get('/buttons', fn () => view('pages.ui-elements.buttons', ['title' => 'Buttons']))->name('buttons');
    Route::get('/image', fn () => view('pages.ui-elements.images', ['title' => 'Images']))->name('images');
    Route::get('/videos', fn () => view('pages.ui-elements.videos', ['title' => 'Videos']))->name('videos');
});

Route::get('/error-404', fn () => view('pages.errors.error-404', ['title' => 'Halaman Tidak Ditemukan']))->name('error-404');
