# ADR 0049 — Tanggal usulan dari draf adalah saat diajukan

## Status

Diterima — 8 November 2026

Sebagian menggantikan [ADR 0048](0048-ekspor-excel-usulan-pengadaan.md):
konsekuensi "usulan dari draf otomatis tercatat diajukan pada saat drafnya
lahir" tidak berlaku lagi.

## Konteks

`ProcurementDraftController::finalize()` mengubah draf menjadi usulan
dengan mengganti `status` dan `user_id`, tetapi `created_at` tetap waktu
draf itu dibuat pemindaian stok (ADR 0027). Padahal draf bisa menunggu
berhari-hari sebelum diajukan.

`created_at` dipakai sebagai tanggal diajukan oleh:

- kolom "Diajukan" di daftar dan detail usulan;
- penyaring tanggal dan ekspor Excel (ADR 0048);
- urutan daftar usulan;
- notifikasi `UsulanMenggantung` (`NotificationScanner::periksaUsulan()`),
  yang menghitung 7 hari sejak `created_at`.

Akibatnya, usulan yang diajukan tanggal 20 tercatat diajukan tanggal 1,
masuk laporan bulan yang salah, dan **langsung** dianggap menggantung
begitu diajukan. Notifikasinya ke Pejabat Pengadaan berbunyi "diajukan 2
minggu yang lalu", padahal baru diajukan saat itu.

ADR 0048 mencatat hal ini sebagai konsekuensi yang diterima. Narendra
menolaknya: draf artinya belum resmi dan belum diproses, jadi tanggal
usulannya adalah saat diajukan.

## Keputusan

`finalize()` mengisi `created_at = now()` bersama `status` dan `user_id`,
dalam transaksi yang sama.

Usulan resmi baru lahir saat diajukan. Sebelum itu ia hanya tampungan
sementara yang dikelola sistem. Dengan begitu, `created_at` setiap usulan
yang bukan draf berarti satu hal yang sama: kapan usulan itu diajukan.
Ini berlaku untuk usulan manual (lahir dan diajukan sekaligus) maupun
usulan dari draf.

Selama masih draf, `created_at` tetap waktu draf dibuat. Halaman draf
masih menampilkannya sebagai "Dibuat otomatis".

Tanpa migrasi. Semua pemakai `created_at` di atas langsung benar tanpa
diubah.

## Alternatif yang ditolak

**Kolom baru `submitted_at`.** Waktu lahir draf akan tetap tersimpan,
tetapi tidak ada yang membutuhkannya setelah draf diajukan. Semua pemakai
`created_at` juga harus dipindah satu per satu, dan yang terlewat akan
diam-diam memakai tanggal yang salah. Narendra juga sudah meminta tanpa
migrasi.

**Membiarkannya (ADR 0048).** Lihat Konteks.

## Konsekuensi

- Usulan dari draf yang **sudah** diajukan sebelum perubahan ini tetap
  memakai tanggal draf lahir. Tidak ada pengisian ulang data lama. Bila
  basis data lapangan sudah memuat usulan seperti itu, tanggalnya bisa
  dipulihkan sekali jalan dari `audit_logs` (`event = ajukan`, `subject`
  usulannya, sejak ADR 0034).
- Awalan UUID pada nomor usulan (`judul()`) tetap mencerminkan waktu draf
  lahir, bukan waktu diajukan. Nomor itu tidak pernah dibaca sebagai
  tanggal, jadi tidak ada yang berubah.
- Usulan dari draf kini muncul di posisi waktu pengajuannya pada daftar
  usulan (terbaru di atas), bukan terselip di antara usulan lama.
