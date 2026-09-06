# ADR 0018 — Berkas unggahan disimpan di disk privat, dan BAST bertanda tangan boleh menyusul

## Status

Diterima — 6 September 2026

## Konteks

Modul Pengadaan membawa kebutuhan yang belum pernah ada di proyek ini:
**mengunggah berkas**. Sampai sekarang tidak ada satu pun jalur unggah di
seluruh aplikasi, dan `config/filesystems.php` masih bawaan Laravel.

Berkas yang perlu ditampung ada dua jenis: BAST bertanda tangan hasil pindai,
dan SPK/kontrak/surat pesanan.

Rancangan lama menyediakan dua tempat yang sama-sama tidak memadai:

- `procurements.additional_doc` — satu kolom string untuk "dokumen tambahan",
  tanpa jenis, tanpa nama asli berkas, tanpa jejak pengunggah.
- `procurement_receipts.file_url` — satu kolom string, dan yang lebih
  bermasalah: **bersifat NOT NULL**.

Kolom NOT NULL itu menyembunyikan kesalahan urutan. Urutan fisik sebuah BAST
adalah: cetak → tanda tangan → pindai → unggah. Mensyaratkan berkasnya ada
sebelum penerimaan dapat dikonfirmasi berarti **memblokir pencatatan stok di
belakang alur pemindaian**. Yang terjadi di lapangan bukan orang jadi lebih
cepat memindai, melainkan konfirmasi ditunda — dan selama ditunda, stok sistem
tertinggal dari stok fisik.

Sisi kedua adalah keamanan. Isi berkasnya adalah kontrak, harga, identitas
penyedia, dan dokumen bertanda tangan milik lembaga pemeriksa keuangan.

## Keputusan

### Satu tabel untuk semua unggahan

Tabel `procurement_documents` menggantikan kedua kolom lama: `procurement_id`,
`type` (`ProcurementDocumentType`: `Bast`, `SpkKontrak`), `file_path`,
`original_name`, `file_size`, `mime_type`, `uploaded_by`, `notes`.

`type` disimpan sebagai string biasa, bukan kolom enum basis data, sehingga
menambah jenis dokumen kelak cukup menambah case pada enum-nya tanpa migrasi.

`procurement_receipts` menjadi murni pencatat **peristiwa** penerimaan: siapa
yang menerima, kapan, dan nomor BAST berapa. Berkasnya bukan urusannya.

### Disk privat, bukan disk publik

Berkas disimpan pada disk **`local`** (`storage/app/private`), **bukan**
`public`. Disk publik berarti berkas dapat diunduh siapa pun yang mengetahui
atau menebak URL-nya, tanpa melewati autentikasi sama sekali — tidak dapat
diterima untuk kontrak dan dokumen bertanda tangan.

Konsekuensinya: `php artisan storage:link` tidak diperlukan, dan unduhan
dilayani rute terautentikasi yang dijaga gate `akses-pengadaan`.

### BAST bertanda tangan boleh menyusul

Penerimaan dikonfirmasi → **stok langsung masuk** → BAST dicetak dari sistem →
ditandatangani → dipindai → diunggah kapan sempat.

Supaya kelonggaran itu tidak berubah menjadi kelalaian, ketiadaan berkas BAST
menjadi **notifikasi keadaan** `BastBelumDiunggah`: muncul beberapa hari
setelah penerimaan, dan menutup dirinya sendiri begitu berkasnya diunggah
— sesuai pemisahan keadaan/peristiwa pada
[ADR 0013](0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md).

Prinsipnya: **sistem yang mengejar kertas, bukan kertas yang menghambat
sistem.**

## Alternatif yang ditolak

**Mempertahankan `file_url` NOT NULL pada `procurement_receipts`.** Menjamin
datanya lengkap: tidak ada penerimaan tanpa bukti. Ditolak karena jaminan itu
semu — yang dijamin bukan berkasnya terunggah, melainkan konfirmasinya
tertunda. Data yang "lengkap tetapi terlambat" lebih buruk daripada data yang
"benar sekarang, buktinya menyusul", karena selama jeda itu stok sistem salah.

**Menyimpan berkas di disk `public` dengan nama acak yang sulit ditebak.**
Praktis: cukup `storage:link` dan berkasnya langsung dapat ditautkan. Ditolak
karena keamanan lewat ketidakjelasan nama bukan kendali akses. URL yang bocor
sekali — lewat riwayat peramban, tangkapan layar, atau tautan yang diteruskan —
berlaku selamanya bagi siapa pun.

**Kolom berkas terpisah untuk BAST (`bast_file_path` nullable pada
`procurement_receipts`) dan tabel terpisah untuk dokumen lain.** Membuat
hubungan BAST dengan penerimaannya lebih eksplisit. Ditolak karena
menghasilkan dua mekanisme unggah, dua jalur unduh, dan dua tempat menghapus
berkas — untuk perbedaan makna yang sudah cukup dinyatakan oleh kolom `type`.

**Menyimpan berkas sebagai BLOB di basis data.** Menjamin berkas dan barisnya
tidak pernah terpisah, termasuk saat pencadangan. Ditolak: membengkakkan
basis data yang di-*backup* rutin, dan tidak ada masalah nyata yang
dipecahkannya di sini.

## Konsekuensi

- Rute unduh wajib melewati autentikasi dan gate; tidak ada tautan langsung ke
  berkas dari Blade.
- `App\Support\DocumentStorage` memusatkan konvensi path, penamaan berkas acak,
  dan penghapusan, supaya tidak tersebar di controller.
- Menghapus sebuah pengadaan harus ikut menghapus berkasnya dari disk — relasi
  `cascadeOnDelete` pada basis data tidak menyentuh berkas fisik.
- Validasi unggahan: `mimes:pdf,jpg,jpeg,png`, maksimal 5 MB.
- Uji memakai `Storage::fake()`, sehingga berkas uji tidak pernah menyentuh
  disk sungguhan.
- Keadaan "sudah diterima tetapi BAST belum diunggah" adalah keadaan yang
  **sah**, bukan galat — dan karenanya harus dapat ditanyakan ke model lewat
  `Procurement::bastSudahDiunggah()`.
