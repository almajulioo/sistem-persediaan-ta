# ADR 0043 — Kategori dan sub kategori barang

## Status

Diterima — 3 November 2026

Dikerjakan **Alma** pada modul Master Barang milik Narendra, atas permintaan
Alma. Menyentuh tabel bersama `items`, jadi **Narendra perlu dikabari**.

## Konteks

Master barang sampai sekarang datar: kode, nama, satuan. Data barang kantor
yang baru (`database/seeders/data/barang.csv`) membawa kolom
`subcategory_code`, dan berkas pendampingnya `kategori_barang.csv` memetakan
75 sub kategori (mis. "ALAT TULIS", "KABEL LISTRIK") ke salah satu dari dua
kategori: **ATK** (Alat Tulis Kantor) dan **ARTK** (Alat Rumah Tangga Kantor).

Kodenya mengikuti kodefikasi barang persediaan: sepuluh digit pertama setiap
`item_code` selalu sama dengan kode sub kategorinya. Pengelompokan ini
dibutuhkan untuk menyaring barang — di Master Barang, dan di pencarian barang
sub sistem Permintaan — dan Pengelola Gudang perlu bisa menambah sub kategori
baru tanpa menunggu pengembang.

Data baru juga berbeda isinya: 1.801 barang (sebelumnya 2.742; barang
obat/kesehatan `1010314…` dan `1010401…` tidak ada lagi) dan saldo awal 120
barang berjumlah 1.792 (sebelumnya 324 barang, 10.560).

## Keputusan

**1. Kategori adalah enum, bukan tabel.** Nilainya hanya dua dan tetap,
`App\Enums\KategoriBarang` (`ATK`, `ARTK`), disimpan sebagai kolom `enum`
basis data. Nilainya `ARTK` mengikuti CSV, bukan "ATRK".

**2. Sub kategori adalah tabel baru `item_subcategories`**:
`subcategory_id` (uuid), `subcategory_code` (unik), `subcategory_name`,
`category` (enum). Model `App\Models\ItemSubcategory`.

**3. `items` mendapat `subcategory_id` (FK, `restrictOnDelete`) dan
`category` (enum).** `items.category` adalah **salinan** kategori sub
kategorinya:

- tidak ada di `$fillable` dan tidak ada di formulir — mengirimnya lewat
  request tidak berpengaruh;
- diisi hook `saving` pada `Item` setiap kali `subcategory_id` berubah, jadi
  berlaku untuk controller, seeder, dan factory sekaligus;
- mengubah kategori sebuah sub kategori ikut memperbarui `items.category`
  seluruh barangnya (hook `updated` pada `ItemSubcategory`).

Kedua kolom **nullable** di basis data semata supaya migrasi aman di basis
data yang sudah berisi barang. Formulir barang mewajibkan sub kategori.

**4. Pengelola Gudang (dan Admin) mengelola sub kategori** di
`/sub-kategori-barang`: tambah, ubah, hapus. Rute ubah di bawah
`can:kelola-gudang`, daftar di bawah `can:akses-gudang` sehingga Kasubbag Umum
dan TI membacanya baca-saja ([ADR 0041](0041-kasubbag-pengawas-baca-saja.md)).
Sub kategori yang masih dipakai barang tidak dapat dihapus. Setiap perubahan
tercatat di log aktivitas dengan modul baru `AuditModule::SubKategoriBarang`
(alias morph `sub_kategori_barang`).

**5. Seeder**: `ItemSubcategorySeeder` (baru) dijalankan sebelum
`ItemSeeder`. `ItemSeeder` menautkan tiap barang ke sub kategorinya lewat
`subcategory_code` dan gagal keras bila kodenya tidak dikenal. Barang lama
yang belum punya sub kategori ikut diisi, tanpa menyentuh stok.

## Alternatif yang ditolak

- **Kategori sebagai tabel sendiri.** Dua nilai tetap tidak butuh layar kelola
  maupun join tambahan.
- **Kategori hanya di sub kategori, tanpa kolom di `items`.** Paling bersih
  dari sisi normalisasi, tetapi kategori barang memang diminta tersimpan di
  `items` supaya penyaringan dan rekap per kategori cukup satu kolom. Risiko
  data gandanya dijaga keputusan 3.
- **Kategori dipilih bebas di formulir barang.** Membuka kemungkinan barang
  ATK berada di sub kategori ARTK; validasi silang hanya menambah pesan galat
  untuk sesuatu yang sebenarnya tidak perlu dipilih.
- **Memvalidasi bahwa `item_code` diawali kode sub kategorinya.** Benar untuk
  data kantor, tetapi barang hasil usulan barang baru
  ([ADR 0032](0032-usulan-barang-baru.md)) dan data uji memakai kode lain;
  belum diminta.

## Konsekuensi

- `items` bertambah dua kolom — perubahan **tabel bersama**, tercatat di
  [erd/penyelarasan-erd.md](../erd/penyelarasan-erd.md). `item_subcategories`
  ikut menjadi tabel bersama karena dirujuk `items`.
- Mendaftarkan barang (termasuk dari usulan barang baru) kini wajib memilih
  sub kategori.
- Basis data yang sudah berisi data lama dan hanya menjalankan `migrate` +
  seeder akan menyimpan barang obat/kesehatan lama **tanpa** sub kategori,
  karena kodenya tidak ada di CSV baru. `migrate:fresh --seed` menghasilkan
  data bersih, tetapi menghapus seluruh data.
- `ItemFactory` kini membuat satu sub kategori per barang, sehingga tes yang
  membuat banyak barang sedikit lebih lambat.
- `x-form.select-field` mendukung `<optgroup>` (nilai opsi berupa array), dipakai
  untuk memilih sub kategori berkelompok ATK/ARTK.
- Pemilih barang di sub sistem Permintaan (`x-request.pemilih-barang`, katalog
  dari `App\Support\KatalogBarang`) menyaring bertingkat di peramban:
  kategori dulu, lalu sub kategori di bawahnya menurut kolom `category` milik
  sub kategori. Barang tanpa sub kategori tetap muncul di "Semua", tetapi
  tidak di chip kategori mana pun.
