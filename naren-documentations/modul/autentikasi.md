# Modul Autentikasi & Otorisasi

Fondasi akses sistem: masuk, keluar, peran, dan penjagaan rute.
Keputusan rancangannya di [ADR 0001](../keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md)
dan [ADR 0002](../keputusan/0002-otorisasi-peran-gate-dan-can.md).

## Rute

| Metode | URL | Nama | Akses |
|---|---|---|---|
| GET | `/login` | `login` | Tamu |
| POST | `/login` | `login` | Tamu |
| POST | `/logout` | `logout` | Terautentikasi |
| GET | `/` | — | Diarahkan ke `/dasbor` |
| GET | `/dasbor` | `dashboard` | Terautentikasi |

Tidak ada rute pendaftaran mandiri — sistem tertutup, akun dibuat Admin.

## Peran

| Peran | Nilai | Keterangan |
|---|---|---|
| Admin | `admin` | Tertinggi; lolos seluruh gate lewat `Gate::before`. Di lapangan dipegang staf TI |
| Kasubbag Umum dan TI | `kasubbag_umum_ti` | Menyetujui atau menolak realisasi pengadaan ([ADR 0030](../keputusan/0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md)) |
| Pengelola Gudang | `pengelola_gudang` | Stok, permintaan, opname |
| Pejabat Pengadaan | `pejabat_pengadaan` | Usulan dan realisasi pengadaan |

Dua peran lain — `kepala_unit` dan `pengguna` — dipakai sub sistem permintaan
barang. `kepala_unit` adalah kepala unit **pemohon**, bukan atasan gudang;
keduanya sengaja tidak dipertukarkan dengan `kasubbag_umum_ti`.

## Gate

| Gate | Peran yang lolos |
|---|---|
| `kelola-pengguna` | Admin |
| `kelola-gudang` | Pengelola Gudang (+ Admin) |
| `kelola-pengadaan` | Pejabat Pengadaan (+ Admin) |
| `kelola-permintaan` | Pengelola Gudang (+ Admin) |
| `setujui-pengadaan` | Kasubbag Umum dan TI (+ Admin) |
| `akses-pengadaan` | Pejabat Pengadaan, Pengelola Gudang, Kasubbag Umum dan TI (+ Admin) |

`akses-pengadaan` ada karena middleware `can:` yang ditumpuk bersifat **DAN**,
bukan ATAU — beberapa halaman pengadaan harus terbuka bagi beberapa peran
sekaligus.

Admin lolos `setujui-pengadaan` juga. Pemisahan staf TI dari pemberi persetujuan
karena itu bersifat organisasional, bukan ditegakkan sistem — keputusan sadar
yang dicatat di ADR 0030.

## Aturan yang berlaku

- Masuk memakai **username atau email** pada satu kolom isian.
- Lima percobaan gagal mengunci **60 detik**, dihitung per kombinasi identitas dan
  alamat IP.
- Identifier sesi diperbarui setelah autentikasi berhasil (cegah *session
  fixation*); saat keluar sesi dihancurkan dan token CSRF diperbarui.
- Akun nonaktif ditolak saat masuk **dan** ditendang keluar pada permintaan
  berikutnya bila dinonaktifkan di tengah sesi aktif.
- Kata sandi minimal 12 karakter memuat huruf besar, huruf kecil, angka, dan
  simbol. Pemeriksaan kebocoran ke layanan luar hanya aktif di produksi, supaya
  pengujian tidak memanggil jaringan.
- Masuk dan keluar tercatat ke `audit_logs`; `last_login_at` dan `last_login_ip`
  diperbarui setiap kali masuk.

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Enums/UserRole.php](../../app/Enums/UserRole.php) | Definisi peran, label, warna badge |
| [app/Http/Controllers/Auth/AuthenticatedSessionController.php](../../app/Http/Controllers/Auth/AuthenticatedSessionController.php) | Masuk dan keluar |
| [app/Http/Requests/Auth/LoginRequest.php](../../app/Http/Requests/Auth/LoginRequest.php) | Validasi dan pembatasan percobaan |
| [app/Http/Middleware/EnsureUserIsActive.php](../../app/Http/Middleware/EnsureUserIsActive.php) | Menendang akun yang dinonaktifkan di tengah sesi |
| [app/Http/Middleware/EnsureUserHasRole.php](../../app/Http/Middleware/EnsureUserHasRole.php) | Middleware `role:` — **tidak** meloloskan Admin |
| [app/Providers/AppServiceProvider.php](../../app/Providers/AppServiceProvider.php) | Gate, kebijakan kata sandi, morph map |
| [app/Support/AuditLogger.php](../../app/Support/AuditLogger.php) | Penulis jejak aktivitas |
| [resources/views/pages/auth/login.blade.php](../../resources/views/pages/auth/login.blade.php) | Halaman masuk |

## Jebakan yang pernah ditemukan

**Middleware `role:` menolak Admin.** `EnsureUserHasRole` memeriksa peran secara
harfiah. Rute yang juga boleh diakses Admin **wajib** memakai `can:<gate>`. Setiap
modul memiliki uji yang memastikan Admin menerima 200, bukan 403.

## Akun awal

Dibuat oleh `UserSeeder`. Kata sandi `Persediaan#2026` di lingkungan lokal, acak
di lingkungan lain dan ditampilkan sekali di terminal.

| Username | Peran |
|---|---|
| `admin` | Admin |
| `gudang` | Pengelola Gudang |
| `pengadaan` | Pejabat Pengadaan |
