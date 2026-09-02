# ADR 0002 — Otorisasi: enum peran, gate, dan `can:` alih-alih `role:`

## Status

Diterima — 1 September 2026

## Konteks

Kolom `users.role` bertipe string bebas, sehingga nilai apa pun dapat tersimpan
tanpa penjagaan. Sistem memiliki tiga peran dengan Admin sebagai yang tertinggi
dan berhak melakukan segalanya.

## Keputusan

1. **Enum PHP `UserRole`** di-*cast* pada model `User`, sehingga nilai peran yang
   tidak dikenali tertolak di lapisan model, bukan hanya di validasi formulir.
2. **Gate berbasis peran** (`kelola-pengguna`, `kelola-gudang`,
   `kelola-pengadaan`, `kelola-permintaan`) dengan `Gate::before` yang
   meloloskan Admin pada seluruh pemeriksaan.
3. **Rute yang juga boleh diakses Admin memakai `can:<gate>`, bukan
   `role:<peran>`.**

Butir ketiga lahir dari jebakan nyata: middleware `EnsureUserHasRole` memeriksa
`hasRole()` secara harfiah tanpa pengecualian Admin. Menulis
`role:pengelola_gudang` pada rute gudang membuat **Admin menerima 403**, padahal
Admin seharusnya bisa melakukan segalanya. Gate tidak punya masalah ini karena
`Gate::before` sudah meloloskan Admin.

Middleware `role:` tetap dipakai untuk rute yang memang khusus satu peran, mis.
`role:admin` pada manajemen pengguna.

## Alternatif yang ditolak

**Paket seperti spatie/laravel-permission.** Berlebihan untuk tiga peran tetap
tanpa izin granular, dan menambah empat tabel beserta ketergantungan yang harus
dipelihara bersama pengembang lain.

**Menambahkan pengecualian Admin ke dalam `EnsureUserHasRole`.** Membuat
`role:pengelola_gudang` diam-diam berarti "pengelola gudang atau admin" —
menyesatkan pembaca kode dan menghilangkan cara menulis rute yang benar-benar
khusus satu peran.

## Konsekuensi

- Setiap rute baru harus memilih dengan sadar: `can:` bila Admin ikut berhak,
  `role:` bila benar-benar eksklusif.
- Jebakan ini mudah terulang, sehingga setiap modul memiliki uji khusus yang
  memastikan **Admin menerima 200**, bukan 403.
- Menambah peran baru cukup dengan menambah case pada enum dan gate terkait.
