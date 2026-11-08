# ADR 0048 — Ekspor Excel usulan pengadaan

## Status

Diterima — 8 November 2026

Sebagian digantikan oleh [ADR 0049](0049-tanggal-usulan-dari-draf-adalah-saat-diajukan.md):
usulan dari draf otomatis kini tercatat diajukan saat diajukan, bukan saat
drafnya lahir.

Sebagian digantikan oleh [ADR 0050](0050-lembar-rekap-barang-pada-ekspor-usulan.md):
lembar "Rekap Barang" yang di bawah tercatat ditolak ternyata salah tafsir,
dan kini menjadi lembar kedua ekspor daftar usulan.

Sebagian menggantikan [ADR 0009](0009-laporan-pdf-dan-csv.md): paket berkas
.xlsx yang dulu ditolak kini dipasang, **hanya untuk ekspor usulan
pengadaan**. Ekspor CSV yang sudah ada (mutasi, laporan pengadaan, log
aktivitas) tidak berubah.

## Konteks

Klien meminta ekspor Excel pada usulan pengadaan, berupa data ringkas
sebagai laporan, dalam dua bentuk:

1. per usulan pengadaan;
2. semua usulan pengadaan dalam satu rentang waktu.

Sebelumnya usulan pengadaan tidak punya ekspor apa pun. Ekspor yang ada
(ADR 0009, ADR 0023) hanya untuk mutasi, realisasi, dan log aktivitas.

Konsep awal yang diajukan lebih luas: kolom baru `submitted_at`,
`decided_at`, `decided_by`, sheet rekap per barang, dan kolom realisasi.
Narendra mempersempitnya:

- rentang waktu berdasarkan **tanggal diajukan**;
- **tanpa** kolom realisasi (nomor dan status);
- **tanpa migrasi**: tidak mencatat siapa dan kapan memutuskan, cukup
  tabel yang sudah ada;
- klien hanya meminta ekspor untuk **skema tabel yang sekarang**.

## Keputusan

### 1. Dua pintu ekspor, isinya sama dengan tabel di layar

| Ekspor | Tombol | Rute | Isi |
|---|---|---|---|
| Daftar usulan | "Ekspor Excel" di daftar usulan | `GET /usulan-pengadaan/laporan/excel` (`procurement-requests.excel`) | Kop (judul, periode, status bila disaring, pencetak), ringkasan per status, lalu satu baris per usulan: No, Nomor Usulan, Tanggal Diajukan, Pengaju, Jumlah Barang, Status |
| Satu usulan | "Unduh Excel" di detail usulan | `GET /usulan-pengadaan/{procurementRequest}/excel` (`procurement-requests.excel-detail`) | Kop, identitas (nomor, status, pengaju, diajukan, jumlah barang), lalu satu baris per barang: No, Kode Barang, Nama Barang, Sumber, Permohonan, Keterangan, Diminta, Disetujui, Satuan |

Tidak ada kolom yang hanya ada di berkas. Kolom daftar mengikuti tabel
daftar usulan. Kolom rincian mengikuti tabel rincian barang di detail
usulan, termasuk isi sel Sumber (`x-pengadaan.sumber-barang`): label
sumber, permohonan penyumbang, dan keterangan. Isi sel itu dipecah menjadi
tiga kolom supaya bisa disaring.

Label ringkasan sama dengan kartu di atas daftar usulan: "Total Usulan",
"Menunggu Persetujuan", "Disetujui, Menunggu Realisasi", "Disetujui,
Selesai", dan "Ditolak". Bedanya, angkanya dihitung dari usulan yang **tersaring**, bukan
seluruh basis data. Dengan begitu angka ringkasan selalu cocok dengan
baris di bawahnya.

Daftar diurutkan **menaik** menurut tanggal diajukan (lalu `procurement_request_id`
sebagai pemecah seri) supaya terbaca sebagai kronologi. Layar tetap
menaruh yang terbaru di atas. Aturan ini sama dengan laporan pengadaan
(ADR 0023).

Draf (ADR 0027) tidak pernah ikut. Daftar memakai `bukanDraf()`, dan
ekspor satu usulan mengembalikan 404 untuk draf.

Rincian **tidak** punya baris jumlah. Satuan barangnya berbeda-beda, jadi
total Diminta atau Disetujui tidak bermakna. Tidak ada kolom rupiah, karena
harga baru lahir di realisasi (ADR 0019). Tidak ada kolom stok, karena stok
pada saat usulan dibuat tidak tersimpan, dan stok hari ini menyesatkan di
laporan.

### 2. Tanggal diajukan adalah `created_at`

Daftar usulan mendapat penyaring **Tanggal Awal** dan **Tanggal Akhir**
yang bekerja pada `created_at`, di `ProcurementRequest::scopeFilter`.
`created_at` adalah tanggal yang sudah ditampilkan sebagai "Diajukan" di
daftar dan detail usulan.

Penyaring dibaca sekali oleh `ProcurementRequestController::filters()`
lalu dipakai bersama layar dan ekspor. Tautan ekspor membawa penyaring
yang sedang aktif, jadi isi berkas sama persis dengan yang dilihat
pengguna, seperti laporan pengadaan (ADR 0009).

Tanggal dari query string yang bukan `Y-m-d` sah (`bukan-tanggal`,
`2026-02-31`) dibuang menjadi kosong. Ini berbeda dari penyaring
realisasi yang meneruskan apa saja ke `whereDate()`. Alasannya, kalimat
periode di kop laporan mengurai tanggal itu, dan tanggal yang tidak sah
akan menjadi 500.

### 3. Berkas .xlsx lewat `phpoffice/phpspreadsheet`

Dipasang `~5.0` (terpasang 5.11.0), bukan `^5.0`, karena `composer.bat`
memakan tanda sisipan (lihat gotcha di `CLAUDE.md`).

CSV tidak cukup untuk "laporan":

- kop dan ringkasan di atas tabel merusak penyaring otomatis Excel;
- tidak ada huruf tebal, lebar kolom, atau pengaturan cetak;
- tanggal terbaca sebagai teks.

ADR 0009 menolak paket ini karena "cukup berat". Alasan itu tidak
berlaku di skala ini: satu baris per usulan, puluhan sampai ratusan
usulan setahun. Paketnya juga satu keluarga dengan `phpoffice/phpword`
yang sudah ada di `composer.lock` (dipakai Alma), dan seluruh ekstensi
PHP yang dibutuhkan (zip, gd, xml, xmlreader, xmlwriter, mbstring, iconv,
fileinfo, zlib, dom) sudah ada di mesin pengembang.

Isi berkas dibangun oleh `App\Support\LaporanUsulanPengadaan`, dengan dua
pintu (`daftar()`, `rincian()`) dan `unduh()` yang mengalirkan berkas
lewat `response()->streamDownload()`. Lembar dicetak A4 mendatar selebar
satu halaman. Judul kolom dibekukan, bisa disaring, dan diulang di setiap
halaman cetak. Tanggal ditulis sebagai tanggal Excel (`dd/mm/yyyy hh:mm`)
dan jumlah sebagai angka.

### 4. Teks selalu ditulis eksplisit sebagai string

Pengikat nilai bawaan PhpSpreadsheet menebak jenis sel dari isinya:

- teks berawalan `=` menjadi **rumus**;
- teks berbentuk angka menjadi **angka**, sehingga kode barang `0012`
  menjadi `12`.

Keterangan barang diketik pengguna. Persoalannya sama dengan injeksi rumus
pada CSV log aktivitas (`ActivityLogController::aman()`), tetapi di sini
diselesaikan pada sumbernya. Setiap teks ditulis dengan
`setCellValueExplicit(..., DataType::TYPE_STRING)`, sehingga isinya
tersimpan apa adanya. Kalau baris itu diganti `setCellValue()` biasa, test
penjaganya gagal.

### 5. Hak akses dan log

Kedua rute di bawah `can:akses-pengadaan`, sama dengan daftar dan detail
usulan: Pengelola Gudang, Pejabat Pengadaan, Kasubbag Umum dan TI (baca
dan cetak, ADR 0041), dan Admin.

Ekspor adalah aksi baca, jadi **tidak** dicatat di log aktivitas. Ini sama
dengan ekspor CSV dan PDF yang sudah ada (aturan wajib 6).

Rute statis `usulan-pengadaan/laporan/excel` didaftarkan di grup paling
awal. Panjang ruasnya sama dengan `usulan-pengadaan/{procurementRequest}/excel`,
jadi bila urutannya terbalik, "laporan" tertangkap sebagai usulan dan
hasilnya 404.

## Alternatif yang ditolak

**Kolom `submitted_at`, `decided_at`, dan `decided_by`.** Ini usulan awal.
`created_at` usulan yang lahir dari draf otomatis adalah waktu **draf**
dibuat, karena `ProcurementDraftController::finalize()` tidak mengubahnya.
Selain itu, siapa dan kapan memutuskan hanya tercatat di `audit_logs`.
Narendra menolaknya: klien hanya meminta ekspor atas skema yang ada, dan
perubahan dibuat seminimal mungkin. Lihat Konsekuensi.

**Sheet "Rekap Barang"** (berapa kali sebuah barang diusulkan, total
diminta dan disetujui, lintas usulan). Tidak diminta. Bisa ditambahkan
kelak sebagai sheet kedua tanpa mengubah skema.

**Kolom realisasi** (nomor dan status realisasi pada daftar usulan).
Narendra menyatakan tidak perlu.

**CSV seperti ekspor lain (ADR 0009).** Lihat keputusan 3.

**`maatwebsite/excel`.** Hanya lapisan di atas PhpSpreadsheet, dengan
konvensi kelas ekspornya sendiri. Di skala ini tidak ada manfaatnya.

**OpenSpout.** Lebih ringan dan menulis secara mengalir. Kemampuan
mengalir itu tidak dibutuhkan untuk ratusan baris, sedangkan
PhpSpreadsheet sekeluarga dengan `phpoffice/phpword` yang sudah dipakai
proyek ini.

**Batas jumlah ekspor** seperti `ProcurementController::guardExportSize()`.
Batas itu ada karena satu realisasi membawa seluruh rinciannya dan
dirender ke PDF. Ekspor daftar usulan hanya satu baris per usulan, dan
volumenya puluhan sampai ratusan per tahun.

## Konsekuensi

- **Usulan dari draf otomatis tercatat diajukan pada saat drafnya
  lahir**, bukan saat diajukan. Ini berlaku di laporan maupun di layar,
  yang sudah begitu sejak ADR 0027. Draf yang menunggu lama bisa membuat
  usulan November masuk laporan September. Bila klien mempersoalkannya,
  jalan keluarnya adalah alternatif pertama di atas, yaitu kolom
  `submitted_at` yang diisi di `store()` dan `finalize()`.
- **Nomor usulan bisa kembar** untuk usulan yang dibuat berdekatan
  (butir terbuka 17 di `CLAUDE.md`). Di laporan rentang waktu, dua baris
  bernomor sama terlihat seperti data ganda.
- **Dependensi baru:** `phpoffice/phpspreadsheet` beserta `composer/pcre`,
  `maennchen/zipstream-php`, `markbaker/complex`, `markbaker/matrix`, dan
  `phpoffice/wmf`. Setiap mesin perlu `composer install`, dan server perlu
  ekstensi zip, gd, dan xml.
- Di mesin pengembang, `vendor/` ternyata belum memuat `phpoffice/phpword`
  milik Alma walau sudah ada di `composer.lock`. Pemasangan ini sekaligus
  memasangnya.
- Ekspor lain tetap CSV. Bila klien kelak meminta Excel untuk mutasi atau
  realisasi, pembantu tulis di `LaporanUsulanPengadaan` (kop, pasangan,
  tabel, tulis) bisa dipindah ke kelas bersama.
- Test membaca balik berkas yang diunduh dengan `IOFactory`, sehingga
  yang diperiksa adalah isi sel sebagaimana dibaca Excel, bukan pemanggilan
  method.
