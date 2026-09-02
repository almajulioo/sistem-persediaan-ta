# ADR 0001 — Autentikasi berbasis sesi tanpa starter kit

## Status

Diterima — 1 September 2026

## Konteks

Sistem akan dipakai di lingkungan internal BPK Perwakilan Provinsi Lampung dan
bersifat tertutup. Aplikasi berupa monolit Blade + Alpine.js di atas template
TailAdmin, bukan SPA maupun API.

Skema `users` sudah ditentukan lebih dulu dari ERD dan menyimpang dari bawaan
Laravel: kunci utama `uuid`, serta kolom `username`, `role`, `unit`, `fullname`,
dan `is_active`. Tidak ada kolom `name`.

## Keputusan

Memakai **autentikasi sesi bawaan Laravel** (guard `web`) yang ditulis sendiri di
atas facade `Auth`, tanpa starter kit.

Lapisan keamanan yang dipasang:

| Aspek | Penerapan |
|---|---|
| Identitas masuk | Username **atau** email pada satu kolom isian |
| Pembatasan percobaan | 5 kali gagal mengunci 60 detik, per kombinasi identitas + IP |
| Session fixation | `session()->regenerate()` setelah autentikasi berhasil |
| Keluar | `invalidate()` + `regenerateToken()` |
| Ganti kata sandi | `AuthenticateSession` menggugurkan sesi di perangkat lain |
| Akun nonaktif | Diperiksa saat masuk **dan** pada setiap permintaan |
| Kebijakan kata sandi | Minimal 12 karakter campuran; pemeriksaan kebocoran hanya di produksi |
| Cookie | Sesi terenkripsi, `http_only`, masa hidup 60 menit, HTTPS dipaksa di luar `local` |
| Jejak | Masuk dan keluar tercatat ke `audit_logs`, plus `last_login_at`/`last_login_ip` |

## Alternatif yang ditolak

**Laravel Breeze / Jetstream / Fortify.** Ketiganya membawa tampilan sendiri yang
akan menimpa template TailAdmin, dan mengasumsikan skema `users` bawaan yang
tidak cocok. Yang paling berbahaya: keduanya memasang rute **pendaftaran
mandiri** — fitur yang justru tidak boleh ada pada sistem tertutup.

**Sanctum / Passport / JWT.** Token hanya diperlukan bila ada klien terpisah
seperti SPA atau aplikasi seluler. Di sini token justru menambah permukaan
serangan: token yang tersimpan di JavaScript dapat dicuri lewat XSS, sedangkan
cookie sesi `http_only` tidak.

## Konsekuensi

- Kode autentikasi ditulis dan diuji sendiri, tetapi jumlahnya kecil dan seluruh
  perilakunya dapat disesuaikan dengan skema `users` yang ada.
- Tidak ada alur reset kata sandi mandiri. Admin yang mengatur ulang kata sandi
  lewat modul manajemen pengguna. Tabel `password_reset_tokens` tetap ada namun
  belum dipakai.
- Karena tanpa starter kit, pembaruan Laravel tidak akan otomatis membawa
  perbaikan pada lapisan ini — perlu ditinjau manual.
