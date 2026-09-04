# Penyelarasan Tabel Bersama

> **Bahas bersama Alma bila ada perubahan pada tabel bersama.**

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
| `items` | Master barang; dirujuk `procurement_details`/`procurement_request_details` (Naren) — Alma belum punya tabel rincian barangnya sendiri, lihat catatan di bawah |
| `stock_mutations` | Buku besar stok; dibangun Naren, ditulis Alma lewat `StockLedger` |
| `audit_logs` | Jejak aktivitas, dipakai kedua sub sistem |

Perubahan pada keempat tabel ini **hanya lewat kesepakatan**. Migrasi dari dua
orang pada tabel yang sama rawan bentrok saat merge.

### Milik Narendra — bebas diubah sendiri

`procurements`, `procurement_details`, `procurement_requests`,
`procurement_request_details`, `procurement_receipts`, `stock_opnames`,
`opname_details`

### Milik Alma — bebas diubah sendiri

`requests`, `basts`, `nota_dinas`

Tidak ada lagi kategori "perbatasan". Sampai 4 September 2026 dokumen ini
mencatat tiga butir perbatasan yang perlu disepakati bersama Alma; ketiganya
gugur setelah ditelusuri ke sumber aslinya — lihat riwayat di bawah.

## Riwayat: kesalahan desain awal yang sudah diperbaiki (5 September 2026)

Desain ERD awal (satu commit, 25 Agustus 2026, tidak pernah disentuh lagi
sampai sesi ini) sempat terbaca seolah menghubungkan sub sistem Pengadaan
dengan sub sistem Permintaan lewat beberapa foreign key. Setelah ditelusuri
langsung ke sumber aslinya, ternyata itu kesalahan penamaan akibat kurangnya
komunikasi saat desain awal — bukan relasi yang disengaja.

| Yang dulu dikira | Kenyataannya | Perbaikan |
|---|---|---|
| `procurement_requests.request_id` adalah FK ke `requests` | Sisa kebingungan penamaan primary key; bukan relasi apa pun | Kolom dihapus |
| `request_details` adalah rincian permintaan unit, milik Alma | Rincian usulan pengadaan, milik Narendra sejak awal | Tabel + model diganti nama menjadi `procurement_request_details` / `ProcurementRequestDetail`, FK diperbaiki menunjuk `procurement_requests` |
| BAST pengadaan cukup kolom string bebas (`procurements.bast_scan`) | Desain yang kurang baik — tanpa nomor dokumen maupun siapa yang mengonfirmasi | Tabel baru `procurement_receipts` |

Konsekuensinya, `App\Models\Request::details()` dan `Request::procurementRequests()`
dihapus — keduanya dibangun di atas premis yang keliru. **`requests` milik Alma
belum punya tabel rincian barangnya sendiri** sama sekali; itu keputusan yang
menyusul saat sub sistem Permintaan dirancang, bukan sesuatu yang sudah ada dan
salah tempat.

Rincian keputusan skema lengkap ada di
[ADR 0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md).

## Titik integrasi yang tersedia — bukan kewajiban

`procurement_requests` punya kolom `source` (enum `ProcurementRequestSource`)
yang membedakan tiga asal-usul usulan pengadaan, dikonfirmasi narasumber
lapangan (Kasubbag Umum dan TI):

1. **`DariPermintaan`** — permintaan unit tidak bisa dipenuhi karena stok
   kurang atau habis.
2. **`PemantauanStok`** — dari notifikasi Stok Habis/Menipis.
3. **`Manual`** — diajukan langsung Pengelola Gudang.

Hanya jenis pertama yang menunjuk dokumen asal, lewat `reference_type`/
`reference_id` polimorfik — pola yang sama dengan `stock_mutations.reference_*`
([ADR 0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md)) —
mengarah ke baris `requests` lewat alias morph map `'permintaan'` yang sudah
terdaftar di `AppServiceProvider`.

Ini **bukan foreign key basis data** dan **bukan kewajiban**: sub sistem
Pengadaan berfungsi penuh tanpa sub sistem Permintaan ada, lewat dua sumber
lainnya. Begitu Alma membangun alurnya, baris `requests` yang menandai stok
kurang tinggal dipakai mengisi kolom ini; sebelum itu, kolomnya dibiarkan
kosong.

## Penyimpangan dari ERD yang sudah diterapkan Naren

Seluruhnya sudah disetujui dan **menyentuh tabel bersama**, sehingga perlu
dikabarkan ke Alma dan tercermin di kedua naskah:

| Tabel | Perubahan | ADR |
|---|---|---|
| `users` | Tambah `last_login_at`, `last_login_ip` | [0001](../keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md) |
| `items` | Tambah `is_active` | [0003](../keputusan/0003-arsip-alih-alih-hapus.md) |
| `stock_mutations` | Tambah `user_id`, `stock_after`, `reference_number`, `notes` | [0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md) |
| `stock_mutations` | `reference` diganti nama menjadi `reference_id` | [0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md) |

## Penyimpangan pada tabel milik Naren

**Tidak perlu kesepakatan dengan Alma** — dicatat di sini semata agar gambar
ERD pada naskah Naren diperbarui.

| Tabel | Perubahan | ADR |
|---|---|---|
| `stock_opnames` | Tambah `status`, `posted_at` | [0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) |
| `stock_opnames` | Tambah `period_type`, `period_year`, beserta indeks gabungannya | [0012](../keputusan/0012-periode-opname-enum-jenis-dan-tahun.md) |
| `stock_opnames` | Tambah `notes` | [0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) |
| `opname_details` | `stock_real` dan `difference` menjadi **nullable** — draf menyimpan barang yang belum dicacah | [0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) |
| `opname_details` | Tambah `counted_at`, `stock_at_posting`, `adjustment_amount` | [0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) |
| `opname_details` | Tambah `adjustment_reason` | [0011](../keputusan/0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md) |
| `procurement_requests` | Hapus `request_id`, `request_type`; tambah `source`, `reference_type`, `reference_id`, `status` | [0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) |
| `request_details` → `procurement_request_details` | Rename tabel + PK; FK diperbaiki menunjuk `procurement_requests` | [0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) |
| `procurements` | Tambah `procurement_request_id`; hapus `bast_scan` | [0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) |
| `procurement_receipts` | Tabel baru — BAST penerimaan dari penyedia ke gudang | [0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) |

## Butir yang perlu dikonfirmasi ke lapangan

1. **Format nomor dokumen resmi BPK** untuk Nota Dinas dan BAST Permintaan
   (milik Alma), maupun BAST Pengadaan (`procurement_receipts.bast_number`,
   milik Narendra). Belum ada satu pun yang dikonfirmasi. Nomor pada data
   contoh masih perkiraan.

Butir soal peran penerima barang terpisah sudah terjawab: **tidak ada**, hanya
Pengelola Gudang dan Admin — lihat [ADR 0008](../keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md).
