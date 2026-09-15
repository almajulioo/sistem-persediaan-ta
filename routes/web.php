<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Gudang\ItemController;
use App\Http\Controllers\Gudang\StockMutationController;
use App\Http\Controllers\Gudang\StockOpnameController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Pengadaan\ProcurementController;
use App\Http\Controllers\Pengadaan\ProcurementDocumentController;
use App\Http\Controllers\Pengadaan\ProcurementDraftController;
use App\Http\Controllers\Pengadaan\ProcurementReceiptController;
use App\Http\Controllers\Pengadaan\ProcurementRequestController;
use App\Http\Controllers\Pengadaan\SupplierController;
use App\Http\Controllers\Request\RequestController;
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
Route::get('/verifikasi/nota-dinas/{requestDocument}/{token}', [RequestController::class, 'verifyNotaDinas'])
    ->whereUuid('requestDocument')
    ->name('request.nota-dinas.verify');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/dasbor');
    Route::get('/dasbor', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profil', function () {
        return view('pages.profile', ['title' => 'Profil']);
    })->name('profile');

    Route::get('/permintaan/create', [RequestController::class, 'create'])->name('request.create');
    Route::post('/permintaan', [RequestController::class, 'store'])->name('request.store');
    Route::get('/permintaan', [RequestController::class, 'index'])->name('request.index');
    Route::get('/permintaan/{request}/nota-dinas', [RequestController::class, 'notaDinas'])->name('request.nota-dinas');
    Route::get('/permintaan/{request}/bast', [RequestController::class, 'bast'])->name('request.bast');
    Route::get('/permintaan/{request}', [RequestController::class, 'show'])->name('request.show');

    Route::middleware('role:kepala_unit,pengelola_gudang,admin')->group(function () {
        Route::get('/permintaan/{request}/edit', [RequestController::class, 'edit'])->name('request.edit');
        Route::put('/permintaan/{request}', [RequestController::class, 'update'])->name('request.update');
        Route::post('/permintaan/{request}/respond', [RequestController::class, 'respondByRole'])->name('request.respond');
    });

    /*
    |----------------------------------------------------------------------
    | Notifikasi
    |----------------------------------------------------------------------
    | Tanpa gate peran: setiap pengguna berhak atas notifikasinya sendiri.
    | Kepemilikan dijaga di controller, per baris, bukan lewat otorisasi rute.
    */
    Route::get('notifikasi', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifikasi/baca-semua', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('notifikasi/{notification}/baca', [NotificationController::class, 'read'])->name('notifications.read');

    /*
    |----------------------------------------------------------------------
    | Usulan pengadaan — daftar dan detail (Pengelola Gudang + Pejabat
    | Pengadaan, dan Admin)
    |----------------------------------------------------------------------
    | Gate `akses-pengadaan` meloloskan kedua peran karena `can:` yang
    | ditumpuk bersifat DAN, bukan ATAU (lihat ADR 0016). Rute `create` (di
    | bawah `can:kelola-gudang`) sengaja didaftarkan sebelum rute `show`
    | berparameter di sini, supaya "usulan-pengadaan/create" tidak tertangkap
    | sebagai {procurementRequest}.
    */
    Route::middleware('can:akses-pengadaan')->group(function () {
        Route::get('usulan-pengadaan', [ProcurementRequestController::class, 'index'])
            ->name('procurement-requests.index');

        Route::get('pengadaan', [ProcurementController::class, 'index'])
            ->name('procurements.index');

        // Cetak laporan. Ruas statis "laporan" didaftarkan di grup ini yang
        // berada paling awal, sesuai kebiasaan berkas ini, walau nyatanya
        // "pengadaan/{procurement}" hanya satu ruas sehingga tidak menelannya.
        Route::get('pengadaan/laporan/pdf', [ProcurementController::class, 'exportPdf'])
            ->name('procurements.pdf');
        Route::get('pengadaan/laporan/csv', [ProcurementController::class, 'exportCsv'])
            ->name('procurements.csv');

        // BAST dicetak dari sistem, lalu ditandatangani dan dipindai di luar
        // sistem. Keduanya boleh dilakukan kedua peran pengadaan (ADR 0018).
        Route::get('pengadaan/{procurement}/bast', [ProcurementController::class, 'bast'])
            ->name('procurements.bast');

        // Berkas pendukung. Tidak ada tautan langsung ke berkasnya: disknya
        // privat, dan seluruh unduhan wajib melewati rute terjaga ini.
        Route::post('pengadaan/{procurement}/dokumen', [ProcurementDocumentController::class, 'store'])
            ->name('procurement-documents.store');
        Route::get('pengadaan/{procurement}/dokumen/{document}/unduh', [ProcurementDocumentController::class, 'download'])
            ->name('procurement-documents.download');
        Route::delete('pengadaan/{procurement}/dokumen/{document}', [ProcurementDocumentController::class, 'destroy'])
            ->name('procurement-documents.destroy');
    });

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

        Route::get('barang/{item}/kartu-persediaan', [StockMutationController::class, 'kartuPersediaan'])
            ->name('items.kartu-persediaan');

        // Riwayat mutasi bersifat immutable: tidak ada rute ubah maupun hapus.
        // Rute cetak didaftarkan lebih dulu agar tidak tertangkap rute berparameter.
        Route::get('mutasi/cetak/pdf', [StockMutationController::class, 'exportPdf'])->name('mutations.pdf');
        Route::get('mutasi/cetak/csv', [StockMutationController::class, 'exportCsv'])->name('mutations.csv');
        Route::get('mutasi/create', [StockMutationController::class, 'create'])->name('mutations.create');
        Route::get('mutasi', [StockMutationController::class, 'index'])->name('mutations.index');
        Route::post('mutasi', [StockMutationController::class, 'store'])->name('mutations.store');

        // Stok opname. Opname yang sudah diposting bersifat final: rute ubah dan
        // hapus menolaknya di controller, bukan disembunyikan dari daftar rute.
        Route::get('opname/{opname}/cetak', [StockOpnameController::class, 'exportPdf'])->name('opname.pdf');
        Route::post('opname/{opname}/posting', [StockOpnameController::class, 'post'])->name('opname.post');
        Route::resource('opname', StockOpnameController::class)->names('opname');

        // Usulan pengadaan: mengusulkan adalah tugas Pengelola Gudang (ADR 0016).
        Route::get('usulan-pengadaan/create', [ProcurementRequestController::class, 'create'])
            ->name('procurement-requests.create');
        Route::post('usulan-pengadaan', [ProcurementRequestController::class, 'store'])
            ->name('procurement-requests.store');

        // Draf usulan otomatis dari pemantauan stok (ADR 0027). Statis, jadi
        // wajib terdaftar sebelum "usulan-pengadaan/{procurementRequest}" di
        // bawah, sama seperti "create" di atas.
        Route::get('usulan-pengadaan/draf', [ProcurementDraftController::class, 'edit'])
            ->name('procurement-requests.draft.edit');
        Route::put('usulan-pengadaan/draf', [ProcurementDraftController::class, 'update'])
            ->name('procurement-requests.draft.update');
        Route::post('usulan-pengadaan/draf/ajukan', [ProcurementDraftController::class, 'finalize'])
            ->name('procurement-requests.draft.finalize');
        Route::delete('usulan-pengadaan/draf', [ProcurementDraftController::class, 'discard'])
            ->name('procurement-requests.draft.discard');

        // Konfirmasi penerimaan barang: tugas Pengelola Gudang, terpisah dari
        // yang merealisasi (ADR 0008). Peristiwa sekali jadi, tanpa rute ubah.
        Route::get('pengadaan/{procurement}/penerimaan/create', [ProcurementReceiptController::class, 'create'])
            ->name('procurement-receipts.create');
        Route::post('pengadaan/{procurement}/penerimaan', [ProcurementReceiptController::class, 'store'])
            ->name('procurement-receipts.store');
    });

    /*
    |----------------------------------------------------------------------
    | Pengadaan (Pejabat Pengadaan, dan Admin)
    |----------------------------------------------------------------------
    | Sama seperti gudang: memakai gate, bukan middleware `role:`, supaya
    | Admin ikut lolos lewat Gate::before.
    */
    Route::middleware('can:kelola-pengadaan')->group(function () {
        Route::resource('penyedia', SupplierController::class)
            ->parameters(['penyedia' => 'supplier'])
            ->names('suppliers');

        Route::patch('penyedia/{supplier}/status', [SupplierController::class, 'toggleStatus'])
            ->name('suppliers.toggle-status');

        // Menyetujui/menolak usulan adalah tugas Pejabat Pengadaan (ADR 0016).
        Route::patch('usulan-pengadaan/{procurementRequest}/setujui', [ProcurementRequestController::class, 'approve'])
            ->name('procurement-requests.approve');
        Route::patch('usulan-pengadaan/{procurementRequest}/tolak', [ProcurementRequestController::class, 'reject'])
            ->name('procurement-requests.reject');

        // Realisasi: merealisasi adalah tugas Pejabat Pengadaan (ADR 0016).
        // "create" wajib terdaftar sebelum "pengadaan/{procurement}" di bawah
        // (gate berbeda, tetap satu berkas rute) — kalau tidak, "create"
        // tertangkap sebagai parameter dan gagal route model binding.
        Route::get('pengadaan/create', [ProcurementController::class, 'create'])
            ->name('procurements.create');
        Route::post('pengadaan', [ProcurementController::class, 'store'])
            ->name('procurements.store');

        // Koreksi realisasi selama belum dikonfirmasi diterima. Salah ketik
        // diperbaiki di sini, bukan dengan membatalkan lalu mengulang dari
        // usulan baru (ADR 0025).
        Route::get('pengadaan/{procurement}/edit', [ProcurementController::class, 'edit'])
            ->name('procurements.edit');
        Route::put('pengadaan/{procurement}', [ProcurementController::class, 'update'])
            ->name('procurements.update');

        // Membatalkan realisasi yang tidak akan pernah sampai ke penerimaan —
        // dokumen milik Pejabat Pengadaan, jadi dialah yang membatalkannya
        // (ADR 0024). Tidak ada rute hapus: realisasi yang batal tetap
        // tersimpan sebagai bukti bahwa pengadaan itu pernah berjalan.
        Route::patch('pengadaan/{procurement}/batal', [ProcurementController::class, 'cancel'])
            ->name('procurements.cancel');
    });

    Route::middleware('can:akses-pengadaan')->group(function () {
        Route::get('usulan-pengadaan/{procurementRequest}', [ProcurementRequestController::class, 'show'])
            ->name('procurement-requests.show');

        Route::get('pengadaan/{procurement}', [ProcurementController::class, 'show'])
            ->name('procurements.show');
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
