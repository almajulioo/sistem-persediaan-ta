# ADR 0031 — Realisasi pengadaan tanpa usulan, berbekal dasar pengadaan

## Status

Diterima — 16 September 2026

## Konteks

Permintaan langsung **Kasubbag Umum dan TI** saat presentasi prototipe di
lapangan: Pejabat Pengadaan perlu dapat membuat realisasi pengadaan sendiri,
tanpa menunggu usulan dari Pengelola Gudang. Di lapangan memang ada belanja
yang lahir dari kebutuhan mendadak, arahan pimpinan, atau belanja rutin
bernilai kecil — dan menunggu siklus usulan untuk kasus-kasus itu tidak
mencerminkan kenyataan kerjanya.

Sampai sekarang hal itu mustahil, dan mustahilnya sengaja:
[ADR 0015](0015-usulan-pengadaan-dan-penerimaan-barang.md) membuat
`procurements.procurement_request_id` **NOT NULL dan UNIQUE** demi
ketertelusuran, dan `ProcurementController::create()` hanya bisa dibuka lewat
tombol "Realisasikan" pada halaman usulan yang sudah disetujui.

### Mengapa baru sekarang layak

Bukan karena keputusan lama keliru, tetapi karena prasyaratnya baru ada.

Sebelum [ADR 0030](0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md),
realisasi berlaku begitu dibuat. Membuka pintu realisasi tanpa usulan saat itu
akan membuat **satu peran menjalankan separuh siklus pengadaan sendirian** —
memilih barang, memilih penyedia, dan menetapkan harga, tanpa dokumen hulu
maupun pihak pemutus. Pemisahan tugas
[ADR 0016](0016-alur-pengadaan-tiga-dokumen.md) akan bolong.

Dengan jenjang Kasubbag Umum dan TI, trionya tetap tiga orang yang berbeda:
Pejabat Pengadaan menyusun, Kasubbag Umum dan TI memutus, Pengelola Gudang
menerima. Yang hilang hanyalah dokumen usulannya, bukan pemisahan tugasnya.

### Mesinnya sudah terbangun

[ADR 0029](0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md) sudah
membangun **baris di luar usulan**: bentuk baris yang mengirim `item_id`
langsung, validasi yang memilah dua bentuk baris, dan `update()` yang tahu
baris tambahan boleh dihapus. Realisasi langsung adalah kasus batas dari bentuk
yang sama — realisasi yang **seluruh** barisnya baris tambahan. Jadi ini
pelonggaran satu kendala, bukan jalur kedua.

## Keputusan

### `procurement_request_id` menjadi nullable, indeks uniknya dipertahankan

Migrasi `2026_09_16_000001_allow_procurements_without_request` mengubah kolom
itu menjadi nullable dan **tidak membuang indeks uniknya**. MySQL maupun SQLite
mengizinkan banyak baris NULL pada indeks unik, sehingga satu perubahan ini
melayani dua kebutuhan sekaligus:

- realisasi langsung boleh sebanyak apa pun, dan
- invarian "satu usulan paling banyak satu realisasi" — dasar
  [ADR 0021](0021-realisasi-dan-penerimaan-pengadaan.md) dan
  [ADR 0024](0024-pembatalan-realisasi-pengadaan.md) — tetap dijaga **basis
  data**, bukan kode.

FK-nya tidak perlu dilepas lebih dulu: yang berubah hanya nullability, bukan
tipe kolomnya. Sudah diperiksa pada MySQL — kolomnya `YES` untuk Null, indeks
`procurements_procurement_request_id_unique` tetap unik, dan FK
`procurements_procurement_request_id_foreign` tetap ada.

### Dasar pengadaan wajib pada realisasi langsung

Dua kolom baru pada `procurements`: `basis_reason` (enum `AlasanPengadaan`) dan
`basis_note` (teks). Keduanya nullable di basis data — NULL berarti "dasarnya
adalah usulannya" — dan kewajibannya ditegakkan di form request, tempat
keberadaan usulan diketahui.

Aturannya satu kalimat: **alasan tidak pernah hilang dari sistem, ia hanya
pindah ke dokumen yang tidak punya hulu.** Rancangan awal bukan "tanpa alasan";
alasannya ada, tempatnya di dokumen usulan beserta pengaju dan `source`-nya.

Tiga hal yang membuatnya wajib, bukan opsional:

1. **Tanpanya layar persetujuan tidak punya apa pun untuk dinilai** selain
   penyedia, barang, dan harga. Pada realisasi berusulan, Kasubbag Umum dan TI
   dapat membuka usulannya dan melihat siapa yang meminta serta mengapa
   (`source: pemantauan_stok` berarti stok di bawah minimum). Hilangkan
   usulannya, hilang pula bahan penilaiannya — dan persetujuan ADR 0030 merosot
   menjadi tanda tangan kosong.
2. **Konteksnya instansi pemeriksa.** `audit_logs` mencatat *siapa* dan
   *kapan*, tidak pernah *mengapa*. Buku mutasi mencatat peristiwa stok, bukan
   dasar belanja. Justru pembelian yang tidak punya dokumen hulu yang paling
   membutuhkan dasar tertulis pada dokumennya sendiri.
3. Ia dapat **direkap**. Inilah penangkal risiko utama keputusan ini, lihat
   bagian Konsekuensi.

Bentuknya mengikuti pola yang proyek ini sudah pakai dua kali — **enum baku
berpasangan keterangan wajib**, seperti `AlasanSelisih`
([ADR 0011](0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md)) dan
`AlasanPembatalan` (ADR 0024). Kolomnya string biasa, bukan enum basis data,
supaya menambah alasan cukup menambah case tanpa migrasi.

`AlasanPengadaan` berisi lima case: `KebutuhanMendesak`, `BarangBaru`,
`ArahanPimpinan`, `BelanjaRutinNilaiKecil`, `Lainnya`.

### Dasar hanya diisi pada realisasi langsung

Pada realisasi berusulan, `basis_reason` dan `basis_note` **disimpan sebagai
NULL** walaupun kiriman memuatnya. Alasannya bukan kerapian: `dasarRingkas()`
menampilkan usulan bila usulannya ada, sehingga alasan kedua yang tersimpan di
sana akan menjadi data yang tidak pernah terlihat siapa pun. Data yang
tersimpan tetapi tak pernah ditampilkan lebih buruk daripada data yang ditolak.

### Keadaan "tanpa usulan" diturunkan, tidak disimpan

`Procurement::tanpaUsulan()` menjawab dari `procurement_request_id === null`.
Tidak ada kolom `source` tersendiri: jawabannya sudah ada pada baris yang sama,
sehingga tidak butuh join dan tidak mungkin menyimpang dari kenyataan. Berbeda
dari [ADR 0028](0028-status-usulan-disetujui-dipecah-dua.md), yang memang harus
menyimpan statusnya karena pertanyaannya tidak terjawab dari satu baris.

Penyaringnya memakai kunci `sumber` (`langsung`/`usulan`) yang diterjemahkan
menjadi `whereNull`/`whereNotNull` pada `scopeFilter()`.

### Dua pintu masuk ke satu formulir

`ProcurementController::create()` melayani keduanya:

| Pintu | Keadaan |
|---|---|
| `GET pengadaan/create?procurement_request_id=…` | Tombol "Realisasikan" pada halaman usulan; usulannya wajib `DisetujuiMenungguRealisasi` |
| `GET pengadaan/create` | Tombol "Buat Realisasi Langsung" pada daftar realisasi |

Query yang **ada tetapi tidak sah** tetap ditolak seperti sebelumnya, bukan
diam-diam dialihkan ke formulir langsung: salah tempel tautan tidak boleh
berubah diam-diam menjadi pengadaan tanpa usulan.

Formulirnya satu berkas dengan dua rupa, bukan dua berkas — bagian "Barang dari
Usulan" dibungkus kondisional, dan Alpine.js-nya sudah menangani
`usulanCount = 0` apa adanya. Dua berkas akan berarti dua sumber kebenaran atas
satu aturan yang sama.

### Validasi: pemeriksaan baris selalu jalan

Sebelumnya `StoreProcurementRequest::withValidator()` langsung berhenti bila
usulannya tidak ada. Sekarang jalan keluar itu dipecah dua: pemeriksaan usulan
(status dan aturan cakupan ADR 0021) hanya jalan bila ada usulan, sementara
pemeriksaan per baris — `item_id` sah, tidak ada barang kembar — **selalu**
jalan. Baris yang mengirim `procurement_request_detail_id` pada realisasi
langsung ditolak sebagai galat.

`rincianDisetujui()` pada kedua form request diberi guard eksplisit yang
mengembalikan koleksi kosong bila tidak ada usulan. Tanpa guard itu kuerinya
berjalan dengan nilai null, yang oleh Laravel diubah menjadi
`where ... is null`; kebetulan hasilnya kosong karena kolom yang dibandingkan
NOT NULL, tetapi bersandar pada kebetulan semacam itu rapuh.

### Mengubah realisasi langsung

Seluruh barisnya tergolong baris tambahan, jadi semuanya boleh dihapus selama
tersisa minimal satu. Dasar pengadaannya ikut dapat diperbaiki — dan seperti
perubahan lain, **penyimpanannya mengembalikan realisasi ke
`MenungguPersetujuan`** serta mengosongkan jejak review (ADR 0030). Tanpa itu,
lubang tata kelola yang ditutup ADR 0030 terbuka lagi lewat pintu baru:
mintakan persetujuan atas dasar yang wajar, lalu tukar dasarnya setelah
disetujui.

Keadaan "tanpa usulan" dibaca dari rutenya, bukan dari kiriman. Usulan asal
tidak dapat dipindah ([ADR 0025](0025-realisasi-dapat-diubah-sebelum-penerimaan.md)),
jadi realisasi langsung selamanya langsung — dan kewajiban dasarnya tidak dapat
dihindari dengan tidak mengirim apa pun.

### Yang tidak berubah satu baris pun

`ProcurementReceiptPoster`, `ProcurementReceiptController`, `StockLedger`,
`ProcurementApprovalController`, dan keadaan `RealisasiMenungguPenerimaan`.
Semuanya bekerja pada `procurement_details` dan status, bukan pada usulan.
Ketiganya tetap diberi uji tersendiri, pola yang sama dengan ADR 0030.

## Alternatif yang ditolak

**Usulan sintetis yang disetujui sendiri.** Sistem membuat satu
`ProcurementRequest` tersembunyi yang langsung berstatus disetujui, sehingga
skemanya tidak berubah sama sekali dan seluruh invarian lama aman. Ditolak
karena dokumennya berbohong: usulan itu akan tercatat sebagai permintaan
Pengelola Gudang yang tidak pernah ia buat, mengotori daftar dan rekap usulan,
serta merusak makna `procurement_requests` sebagai dokumen milik gudang. Skema
yang jujur lebih murah dirawat daripada data yang menipu.

**Kolom `source` tersimpan pada `procurements`.** Ditolak karena dapat
menyimpang dari `procurement_request_id`, dan penyaringnya tidak butuh join
sama sekali sehingga tidak ada yang dibeli dengan penyimpanan itu. Bandingkan
dengan ADR 0028 yang memang menyimpan statusnya — di sana pertanyaannya tidak
terjawab dari satu baris.

**Membuang indeks uniknya.** Cara paling cepat membuat kolomnya nullable.
Ditolak karena indeks itulah satu-satunya penjaga invarian "satu usulan satu
realisasi", dan memindahkannya ke kode berarti menukar jaminan basis data
dengan disiplin yang bisa lupa.

**Realisasi langsung tanpa dasar tertulis.** Paling ringan, dan sempat menjadi
pertanyaan pengguna sendiri karena rancangan awal memang tidak punya kolom
alasan pada realisasi. Ditolak dengan tiga alasan di bagian Keputusan; yang
paling menentukan: persetujuan tanpa bahan penilaian bukan persetujuan.

**Alasan berupa teks bebas saja, tanpa enum.** Lebih sederhana dan mengikuti
pola `review_note` ADR 0030. Ditolak karena tidak dapat direkap — tanpa enum,
kebiasaan memakai pintu langsung tidak akan pernah terlihat angkanya, dan
justru itu penangkal risiko utama keputusan ini.

**Kuota atau pemblokiran realisasi langsung.** Misalnya batas nilai rupiah atau
jumlah per bulan. Ditolak karena angkanya belum diketahui, aturan yang salah
tebak justru mendorong staf mengakalinya, dan tiga penangkal di bawah sudah
cukup tanpa menebak apa pun.

## Konsekuensi

**Pintu langsung bisa menjadi kebiasaan.** Inilah risiko utama keputusan ini:
bila setiap belanja lewat pintu ini, usulan Pengelola Gudang terlewat dan draf
usulan otomatis [ADR 0027](0027-draf-usulan-pengadaan-otomatis.md) menjadi
pajangan. Sengaja tidak dilawan dengan larangan, melainkan dengan membuatnya
**terlihat**:

1. `basis_reason` berenum sehingga dapat direkap — kelihatan bila
   `KebutuhanMendesak` dipakai empat puluh kali setahun.
2. Rekap layar dan laporan memisahkan cacah realisasi langsung, dan tabelnya
   menandai barisnya dengan label "Langsung".
3. Kasubbag Umum dan TI tetap memutus setiap satuannya.

Kebiasaan yang terlihat angkanya dapat ditegur; yang tidak terlihat tidak.

**`langsung` adalah irisan, bukan penjumlah.** Pada `summary()` ia dihitung
dari realisasi yang bukan `takPernahJadiTransaksi()` dan `procurement_request_id`
NULL, sehingga invarian ADR 0030
`total = menunggu_persetujuan + menunggu + diterima` tidak terganggu.

**Laporan bertambah satu keterangan, bukan satu kolom.** Laporan PDF sudah
sebelas kolom pada kertas lanskap, jadi dasar pengadaan ditaruh sebagai baris
kedua di dalam sel "Realisasi" bersama statusnya. Ekspor CSV justru menambah
kolom **Dasar** tersendiri karena lebarnya tidak terbatas. Aturan
[ADR 0023](0023-laporan-pengadaan-per-baris-barang.md) — satu baris per barang
per realisasi — tidak berubah.

**Isi notifikasi persetujuan bercabang.** `RealisasiMenungguPersetujuan`
menyebut bahwa realisasinya tanpa usulan beserta alasannya. Murah, dan perlu:
pada realisasi langsung, dasar itulah yang justru dinilai, bukan cuma harganya.

**Usulan pengadaan tidak menjadi mubazir.** Pengelola Gudang tetap tidak dapat
membeli apa pun, jadi ia tetap harus meminta. Yang berubah hanya: permintaannya
bukan lagi satu-satunya jalan masuk.

**Uji baru 22 buah** di `tests/Feature/Pengadaan/ProcurementDirectTest.php`,
termasuk matriks hak akses beserta Admin → 200, pembuktian bahwa dua realisasi
langsung dapat berdiri bersama (inti migrasinya), gugurnya persetujuan setelah
dasarnya diubah, dan penerimaan yang tetap memposting stok lewat `StockLedger`.
Seluruh suite 410 lolos.

### ADR yang diperluas, bukan digantikan

- **ADR 0015** — `procurement_request_id` nullable membalik satu keputusannya.
  Yang tidak dibalik adalah maksudnya: ketertelusuran tetap dituntut, hanya
  pindah tempat ke `basis_reason`/`basis_note`.
- **ADR 0016** — pemisahan tugas tetap utuh, dengan trio yang berbeda.
- **ADR 0021** — aturan cakupan tetap berlaku sepenuhnya bila ada usulan.
- **ADR 0025**, **ADR 0030** — aturan reset persetujuan berlaku penuh, termasuk
  atas perubahan dasar pengadaan.
- **ADR 0026**, **ADR 0030** — aturan agregat tidak berubah.

Tidak ada ADR yang berubah status menjadi digantikan.

### Langkah berikutnya yang belum dikerjakan

Belanja barang yang belum terdaftar di master barang adalah permintaan kedua
dari Kasubbag Umum dan TI pada presentasi yang sama, dan dijawab oleh
[ADR 0032](0032-usulan-barang-baru.md) — bukan oleh ADR ini.
