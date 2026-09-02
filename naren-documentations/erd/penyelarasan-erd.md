# Penyelarasan Tabel Bersama

> **Bahas bersama Alma sebelum modul Pengadaan dimulai.**

## ERD yang berbeda itu wajar

Narendra dan Alma menulis **skripsi terpisah dengan judul berbeda**, masing-masing
tentang sub sistemnya sendiri. Karena itu **ERD keduanya memang berbeda, dan itu
benar** — bukan kesalahan yang harus disatukan menjadi satu gambar.

Yang perlu diselaraskan hanya **tabel yang dipakai kedua sub sistem**. Bila
sebuah tabel muncul di kedua ERD, kolomnya harus digambar sama persis. Sumber
kebenarannya adalah migrasi yang benar-benar berjalan di
`database/migrations/`, bukan gambar ERD di naskah.

## Peta kepemilikan tabel

### Tabel bersama — wajib identik di kedua ERD

| Tabel | Peran |
|---|---|
| `users` | Autentikasi dan peran, dipakai kedua sub sistem |
| `items` | Master barang; dirujuk `request_details` (Alma) dan `procurement_details` (Naren) |
| `stock_mutations` | Buku besar stok; dibangun Naren, ditulis Alma lewat `StockLedger` |
| `audit_logs` | Jejak aktivitas, dipakai kedua sub sistem |

Perubahan pada keempat tabel ini **hanya lewat kesepakatan**. Migrasi dari dua
orang pada tabel yang sama rawan bentrok saat merge.

### Milik Narendra — bebas diubah sendiri

`procurements`, `procurement_details`, `procurement_requests`, `stock_opnames`,
`opname_details`

### Milik Alma — bebas diubah sendiri

`requests`, `request_details`

### Perbatasan — perlu kesepakatan

| Tabel / kolom | Mengapa jadi perbatasan |
|---|---|
| `procurement_requests.request_id` → `requests` | Usulan pengadaan (Naren) lahir dari permintaan (Alma) yang stoknya tidak mencukupi. Foreign key melintasi kepemilikan |
| `basts`, `nota_dinas` | Belum jelas milik siapa. BAST muncul di dua konteks: penerimaan barang dari pengadaan, dan penyerahan barang atas permintaan |
| `requests.bast_number`, `requests.nd_number` | Nomor dokumen tersimpan di `requests` **sekaligus** di tabel `basts`/`nota_dinas` |

## Temuan, dikelompokkan menurut pemiliknya

| # | Temuan | Pemilik | Tindakan |
|---|---|---|---|
| 1 | ERD `ProcurementRequest` memiliki `request_date`; migrasi `procurement_requests` tidak | **Naren** | Diperbaiki sendiri saat modul Pengadaan dibangun |
| 2 | `requests` menjadi induk `request_details` **sekaligus** `procurement_requests` | **Perbatasan** | Sepakati bentuk keterkaitan usulan pengadaan dengan permintaan |
| 3 | `requests` menyimpan `bast_number`/`nd_number`, sementara tabel `basts`/`nota_dinas` juga ada | **Perbatasan** | Tetapkan satu sumber nomor dokumen |
| 4 | `RequestDetail` digambar di bawah `ProcurementRequest`, tetapi foreign key-nya menunjuk `requests` | **Alma** | Perbaiki gambar ERD agar sesuai migrasi |

Temuan #3 **bertaut langsung** dengan `stock_mutations.reference_number` pada
[ADR 0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md): buku
mutasi menyalin nomor dokumen resmi secara permanen, sehingga sumber nomor itu
harus tunggal dan pasti.

## Saran untuk dibahas

1. **Satu sumber kebenaran untuk nomor dokumen.** Pindahkan `bast_number` dan
   `nd_number` dari `requests` ke tabel `basts` dan `nota_dinas`, lalu tautkan
   lewat foreign key. Hapus kolom string kembarannya agar tidak ada dua versi
   nomor yang sama.
2. **Tetapkan pemilik `basts` dan `nota_dinas`.** Bila BAST dipakai di dua
   konteks, pertimbangkan kolom penanda jenis, atau dua tabel terpisah dengan
   kepemilikan yang jelas.
3. **Sepakati kontrak `procurement_requests` → `requests`.** Naren perlu membaca
   permintaan yang stoknya kurang; Alma perlu tahu permintaannya telah menjadi
   usulan pengadaan.
4. **Kunci bentuk keempat tabel bersama sekarang**, sebelum kedua sub sistem
   berkembang lebih jauh. Perubahan setelah itu jauh lebih mahal.
5. **Gambar ERD masing-masing hanya memuat tabel yang relevan** dengan sub sistem
   yang ditulis, ditambah tabel bersama yang dirujuk. Tabel bersama digambar sama
   persis di kedua naskah.

## Penyimpangan dari ERD yang sudah diterapkan Naren

Seluruhnya sudah disetujui dan **menyentuh tabel bersama**, sehingga perlu
dikabarkan ke Alma dan tercermin di kedua naskah:

| Tabel | Perubahan | ADR |
|---|---|---|
| `users` | Tambah `last_login_at`, `last_login_ip` | [0001](../keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md) |
| `items` | Tambah `is_active` | [0003](../keputusan/0003-arsip-alih-alih-hapus.md) |
| `stock_mutations` | Tambah `user_id`, `stock_after`, `reference_number`, `notes` | [0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md) |
| `stock_mutations` | `reference` diganti nama menjadi `reference_id` | [0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md) |

## Butir yang perlu dikonfirmasi ke lapangan

1. **Format nomor dokumen resmi BPK** untuk Nota Dinas, BAST, dan dokumen
   pengadaan. Sampai ini pasti, nomor pada data contoh hanya perkiraan.
2. **Apakah ada peran penerima barang terpisah**, misalnya Pejabat atau Panitia
   Penerima Hasil Pekerjaan, di luar tiga peran yang sudah ada. Bila ada,
   `UserRole` perlu bertambah — dan `users` adalah tabel bersama, sehingga harus
   disepakati bersama Alma. Lihat
   [ADR 0008](../keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md).
