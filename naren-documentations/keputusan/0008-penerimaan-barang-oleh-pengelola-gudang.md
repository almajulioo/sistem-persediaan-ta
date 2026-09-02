# ADR 0008 — Penerimaan barang dikonfirmasi Pengelola Gudang

## Status

Diterima — 3 September 2026

## Konteks

Rancangan awal menyebut Pengelola Gudang hanya sampai usulan pengadaan,
selebihnya ditangani Pejabat Pengadaan sampai barang masuk ke stok. Dengan
rancangan itu, mutasi `Masuk` akan diatribusikan kepada Pejabat Pengadaan.

Pertanyaan muncul saat meninjau data contoh: mengapa petugas pada mutasi Barang
Masuk adalah Pengelola Gudang, bukan Pejabat Pengadaan?

Peninjauan menunjukkan masalahnya lebih dalam daripada sekadar atribusi. Bila
satu peran dapat mengadakan barang **sekaligus** menyatakan barang itu masuk
gudang, tidak ada pihak kedua yang memverifikasi bahwa barang benar-benar
diterima.

## Keputusan

Menyelesaikan realisasi pengadaan **tidak** menambah stok. Pengadaan berpindah ke
status *Menunggu Penerimaan*. Stok baru bertambah ketika **Pengelola Gudang
mengonfirmasi barang fisik diterima**, dan mutasi `Masuk` diatribusikan
kepadanya.

Alasannya **pemisahan tugas**. Pola ini juga sejalan dengan praktik pengadaan
pemerintah, tempat Pengurus atau Penyimpan Barang berperan terpisah dari Pejabat
Pengadaan, dan penerimaan dituangkan dalam berita acara.

Kolom `user_id` pada mutasi karena itu bermakna *petugas yang mengeksekusi
transaksi*, bukan petugas yang mengetik data. Mutasi otomatis pun tetap dipicu
manusia, dan kartu persediaan seharusnya dapat menjawab siapa yang bertanggung
jawab tanpa membuka dokumen lain.

## Alternatif yang ditolak

**Pejabat Pengadaan menyelesaikan sampai stok bertambah.** Alurnya lebih pendek,
tetapi satu peran mengendalikan pengadaan sekaligus pencatatan stok — celah
kontrol yang akan disorot pemeriksa.

**Menampilkan Sistem sebagai petugas untuk seluruh mutasi otomatis.** Sesuai
intuisi bahwa baris itu dibuat sistem, tetapi kartu persediaan kehilangan
informasi siapa yang mengeksekusi, sehingga harus membuka dokumen sumber.

**Menyimpan pelaksana realisasi dan penerima sebagai dua kolom terpisah pada
mutasi.** Paling lengkap, tetapi informasi pelaksana realisasi sudah ada pada
dokumen pengadaan; menyalinnya ke buku mutasi menduplikasi data.

## Konsekuensi

- Modul Pengadaan **wajib** memiliki status *Menunggu Penerimaan* beserta layar
  konfirmasi penerimaan untuk Pengelola Gudang. Ini menentukan rancangannya sejak
  awal.
- Kolom Petugas didampingi kolom **Sumber** pada layar riwayat, agar pembaca tahu
  mana baris yang otomatis dari dokumen dan mana yang dicatat tangan.
- Perlu dikonfirmasi ke lapangan apakah ada peran penerima barang terpisah,
  misalnya Pejabat atau Panitia Penerima Hasil Pekerjaan. Bila ada, `UserRole`
  perlu bertambah — dan `users` adalah tabel bersama, sehingga harus disepakati
  kedua pengembang.
