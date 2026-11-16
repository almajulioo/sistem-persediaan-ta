# ADR 0058 — Saldo awal 1 Oktober diganti saldo awal 1 November sebelum sistem dipakai

## Status

Diterima — 14 November 2026

Pengecualian sekali pakai atas
[ADR 0005](0005-buku-mutasi-immutable-dan-urutan-pencatatan.md): baris mutasi
saldo awal hasil seeder boleh dihapus, **hanya** selama buku mutasi belum berisi
kejadian nyata.

## Konteks

Server produksi diisi seeder pada 11 November 2026 dengan saldo awal per
1 Oktober 2026 (`saldo_awal_2026_10_01.csv`: 120 barang, 1.792 unit). Sistem
belum dipakai bertransaksi. Sementara itu kantor menyerahkan saldo awal per
1 November 2026 (`Saldo Awal 1 Nov 2026.xlsx`): 122 barang, 1.902 unit, dengan
5 kode barang yang belum ada di master.

Selisih Oktober–November terjadi di luar sistem dan tidak punya dokumen di
sistem. Mencatatnya sebagai mutasi (mis. Koreksi Pencatatan bertanggal
31 Oktober) berarti mengarang sekitar 60 mutasi tanpa dasar di kartu
persediaan.

## Keputusan

1. **Tanggal mulai sistem adalah 1 November 2026.** `ItemSeeder` memakai
   `saldo_awal_2026_11_01.csv` (diturunkan dari berkas Excel, hanya baris
   bersaldo). Berkas 1 Oktober dihapus dari repositori; isinya tetap ada di
   riwayat git.
2. **Lima barang baru ditambahkan ke `barang.csv`** dengan sumber `saldo awal`.
   Excel tidak memuat satuan, jadi satuannya mengikuti barang sejenis di
   master: empat lampu Philips "(Isi 3+1)" memakai **Set**, seperti "Batu
   Baterai … (isi 4+2)"; "MCB 16 Schneider" memakai **Pcs**, seperti
   "mcb 16A".
3. **Server yang sudah terisi diganti lewat `php artisan
   persediaan:ganti-saldo-awal`.** Perintah ini menghapus mutasi saldo awal
   lama, mengosongkan stok, lalu mencatat saldo awal baru lewat `StockLedger`.
   Barang tanpa saldo diarsipkan dan barang bersaldo diaktifkan, sama dengan
   aturan seeder. Akun pengguna dan `item_id` tidak berubah, jadi
   `migrate:fresh` tidak diperlukan.
4. **Pengaman.** Perintah menolak berjalan bila sudah ada mutasi selain saldo
   awal, ada saldo awal yang tidak bertanggal 1 Oktober (mis. barang yang
   didaftarkan lewat aplikasi), atau ada permintaan, pengadaan, atau stok
   opname. Tanpa `--jalankan` perintah hanya menampilkan pratinjau. Setelah
   berhasil, menjalankannya lagi tidak mengubah apa pun.
5. Sesudah penggantian, `notifikasi:periksa` dijalankan supaya notifikasi stok
   dan draf usulan pengadaan otomatis (ADR 0027) mengikuti stok baru. Satu baris
   log aktivitas (modul Mutasi, aksi Ubah) dicatat atas nama Admin.

## Alternatif yang ditolak

**Mencatat selisih sebagai mutasi.** Mengarang riwayat yang tidak terjadi di
sistem dan mengotori kartu persediaan sejak hari pertama.

**`migrate:fresh --seed` di produksi.** Akun dibuat ulang dengan UUID dan kata
sandi bawaan, dan sesi terputus. Penggantian yang terarah cukup.

**Mengedit baris di phpMyAdmin.** `stock_current`, `stock_after`, dan status
arsip harus selaras; mengedit manual 130-an barang hampir pasti meleset.

## Konsekuensi

- Setelah sistem dipakai, saldo awal tidak bisa lagi diganti dengan cara ini;
  perubahan stok lewat stok opname atau Koreksi Pencatatan seperti biasa.
- Perintah `persediaan:ganti-saldo-awal` boleh dihapus setelah produksi
  diganti.
- Spons Scotch Brite ID-30 tercatat dengan dua kode (`…000022` dan
  `…000031`), mengikuti berkas kantor apa adanya.
