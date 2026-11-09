# Modul Stok Opname

Pencacahan fisik persediaan per periode, penyesuaian stok atas selisih yang
ditemukan, dan **Laporan Stok Opname** (Excel) sebagai dokumen
pertanggungjawaban.

Modul terakhir sub sistem manajemen gudang. Seluruh penyesuaiannya mengalir
lewat `StockLedger`, sehingga hasil opname tampil sebagai baris **Penyesuaian
Opname** di riwayat mutasi dan menunjuk balik ke dokumen opname-nya.

Sejak 9 November 2026 petugas **cukup mengisi barang yang jumlahnya berbeda**;
barang yang dikosongkan mengikuti stok sistem saat diposting
([ADR 0056](../keputusan/0056-barang-tidak-diisi-mengikuti-stok-sistem.md)), dan
laporannya berkas Excel, bukan PDF
([ADR 0055](../keputusan/0055-laporan-stok-opname-dalam-excel.md)).

## Rute

Rute yang mengubah data (mulai, cacah dan posting, hapus draf) di bawah
`middleware('can:kelola-gudang')` — Pengelola Gudang dan Admin. Daftar, rincian,
dan unduhan Excel di bawah `can:akses-gudang`, yang juga meloloskan Kasubbag
Umum dan TI baca-saja: draf tampil dengan "Lihat", bukan "Lanjut Cacah", dan
tombol "Mulai Opname" tidak ada
([ADR 0041](../keputusan/0041-kasubbag-pengawas-baca-saja.md)). Pejabat
Pengadaan menerima 403 dan menunya tidak muncul.

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/opname` | `opname.index` | Daftar + 3 penyaring + kartu rekap |
| GET | `/opname/create` | `opname.create` | Formulir mulai opname |
| POST | `/opname` | `opname.store` | Buat draf + snapshot seluruh barang aktif |
| GET | `/opname/{opname}` | `opname.show` | Hasil opname dan rincian; penyaring `?baris=diisi\|berselisih\|tidak_diisi` |
| GET | `/opname/{opname}/edit` | `opname.edit` | Layar pencacahan — draf saja |
| PUT | `/opname/{opname}` | `opname.update` | Simpan baris yang berubah; dengan `aksi=posting` sekaligus posting |
| GET | `/opname/{opname}/excel` | `opname.excel` | Laporan Stok Opname (.xlsx) |
| DELETE | `/opname/{opname}` | `opname.destroy` | Hapus **draf saja** |

Rute `POST /opname/{opname}/posting` dan `GET /opname/{opname}/cetak` (PDF)
sudah tidak ada sejak ADR 0056 dan 0055.

## Alur

```
Draf  ──simpan baris yang berubah (berkali-kali)──►  Draf
  │
  └──posting (isian di layar ikut tersimpan)──►  Selesai  ──►  unduh Excel
```

**Draf.** Dibuat dengan menyalin seluruh barang aktif beserta stok tercatatnya ke
`opname_details.stock_stored`. Barang yang didaftarkan setelah draf dibuat tidak
ikut — opname mencakup barang yang ada saat pencacahan dimulai. Draf dapat
disimpan sebagian dan dapat dihapus, karena belum menyentuh stok sama sekali.

Setiap penyimpanan yang **benar-benar mengubah** baris menulis satu baris
`audit_logs`, lengkap dengan kemajuannya ("3 barang diubah, 7 dari 120 barang
diisi") supaya pengisian awal dapat dibedakan dari koreksi menjelang posting
([ADR 0034](../keputusan/0034-log-aktivitas-terstruktur.md)). Kiriman tanpa
perubahan tidak dicatat.

**Posting** dikirim lewat `opname.update` dengan `aksi=posting`: isian di layar
disimpan dulu, lalu `OpnamePoster` dijalankan. Posting ditolak bila:

1. Dokumen bukan lagi draf;
2. Ada baris berselisih tanpa alasan **dan** keterangan.

Barang yang **belum diisi tidak lagi menghalangi posting** — ia mengikuti stok
sistem (lihat di bawah). Bila posting gagal, isian yang baru disimpan tetap ada
di draf.

**Selesai.** Dokumen final: tidak dapat diubah, dihapus, maupun diposting ulang.
Penyesuaiannya sudah menjadi bagian buku mutasi yang immutable
([ADR 0005](../keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)).
Laporan dapat diunduh ulang kapan saja.

Lihat [ADR 0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md)
dan [ADR 0056](../keputusan/0056-barang-tidak-diisi-mengikuti-stok-sistem.md).

## Angka pada satu baris rincian

| Kolom | Arti |
|---|---|
| `stock_stored` | Stok tercatat **saat draf dibuat** — "Stok Sistem" di layar |
| `stock_real` | Hasil cacah fisik; kosong = belum diisi |
| `counted_at` | Saat petugas **memasukkan** angka itu; kosong = tidak diisi petugas |
| `difference` | `stock_real − stock_stored` — selisih yang teramati saat mencacah |
| `stock_at_posting` | Stok tercatat **pada detik posting** |
| `adjustment_amount` | Mutasi yang benar-benar diterapkan = `difference` |

`stock_at_posting ≠ stock_stored` pada baris yang **diisi petugas** berarti stok
bergerak setelah barang dicacah. Baris itu ditandai di layar hasil dan laporan.

## Barang yang tidak diisi mengikuti stok sistem

Saat posting, baris yang `stock_real`-nya kosong diisi `stock_real =
stock_stored` dan `difference = 0`: tidak ada mutasi, dan stok akhirnya tetap
stok sistem pada detik posting. `counted_at` dibiarkan kosong — itulah
penandanya:

| Metode `OpnameDetail` | Arti |
|---|---|
| `diisiPetugas()` | `counted_at` terisi |
| `mengikutiStokSistem()` | `stock_real` terisi, tetapi tidak diisi petugas |
| `stokBerubahSejakDicacah()` | Hanya baris yang diisi petugas |

Hasil cacahnya disamakan dengan `stock_stored`, bukan `stock_at_posting`: yang
kedua menerapkan ulang mutasi sah sejak draf dibuat (100 → keluar 20 → 80, lalu
penyesuaian −20 lagi menjadi 60). `StockOpname::ringkasan()` memuat `barang`,
`diisi`, `tidak_diisi`, `selisih`, `lebih`, `kurang`.

## Aturan penyesuaian

**Yang dikoreksi opname adalah kesalahan pencatatan yang ditemukan saat
mencacah**, yaitu `difference`. Transaksi yang terjadi setelah pencacahan adalah
kejadian sah yang sudah tercatat sendiri di buku mutasi, dan penyesuaian opname
tidak membatalkannya.

```
stok akhir = stock_at_posting + difference
```

Bila tidak ada transaksi di antara pencacahan dan posting, stok akhir sama
dengan hasil cacah. Bila ada, stok akhir berbeda dari hasil cacah — dan itu
benar. Rumus lawan yang memaksa stok menjadi sama dengan `stock_real` sempat
diterapkan lalu ditolak karena membatalkan transaksi sah; alasannya diuraikan
pada [ADR 0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md).

Ketentuan lain:

- Baris tanpa selisih **tidak menghasilkan mutasi**, tetapi tetap mencatat
  `adjustment_amount = 0` sebagai bukti sudah diperiksa.
- Seluruh penerapan berjalan dalam satu `DB::transaction`. Baris barang dikunci
  `lockForUpdate()` sebelum selisih dibaca, sehingga tidak ada celah balapan.
- Penyesuaian kurang yang membuat stok minus ditolak `StockLedger`, dan
  **seluruh posting dibatalkan** — bukan sebagian diterapkan.
- Mutasi yang lahir berjenis `Penyesuaian`, `reference` menunjuk objek
  `StockOpname`, `reference_number` berisi judul opname, dan `notes` dirakit
  dari alasan + keterangan petugas.
- `mutation_date` memakai `opname_date`, bukan tanggal posting.

## Layar pencacahan

Tabel dirender di klien (Alpine) dari data JSON, 50 baris sekali tampil:

- Kotak **Stok Fisik** ber-placeholder stok sistem; selama kosong, kolom
  Selisih berlabel **Ikut sistem**. Satu kalimat petunjuk di atas tabel
  menjelaskan aturannya (permintaan Narendra, pengecualian aturan UI bersih).
- Kartu ringkasan berjalan: Total Barang, Diisi (sesuai · berselisih), Selisih
  Lebih / Kurang, Ikut Stok Sistem.
- Pencarian kode/nama; penyaring Semua / Diisi / Berselisih / Ikut Stok Sistem
  / **Perlu Dilengkapi** (angka tidak sah, atau selisih tanpa alasan/keterangan).
  Daftar disaring ulang hanya saat penyaring atau kata kunci berganti.
- Alasan dan keterangan muncul saat baris berselisih. **Enter** berpindah ke
  alasan → keterangan → baris berikutnya.
- "N perubahan belum disimpan", **Simpan Draf** nonaktif tanpa perubahan, dan
  peringatan peramban saat meninggalkan halaman dengan perubahan.
- **Posting Penyesuaian** membuka modal ringkasan yang menyebut berapa barang
  diisi petugas dan **berapa yang mengikuti stok sistem**.
- Yang dikirim hanya baris yang berubah, sebagai satu JSON di `hasil_cacah` —
  `max_input_vars` PHP (bawaan 1000) memotong formulir ribuan isian diam-diam.
  `details[...]` biasa tetap diterima server.

## Alasan selisih

Enam alasan baku **berpasangan** keterangan bebas, keduanya wajib untuk setiap
baris yang berselisih: Barang Rusak, Barang Hilang, Kadaluarsa, Kesalahan
Pencatatan, Transaksi Belum Dibukukan, Lainnya. Baris yang kembali sesuai atau
dikosongkan membuang alasan dan keterangannya.

Aturan wajib ini ditegakkan dua kali — di `UpdateOpnameCountRequest` sebagai
validasi formulir, dan di `OpnamePoster::pastikanSiap()` sebagai penjaga domain.
Nilai `stock_stored` dibaca dari basis data, bukan dari request, supaya tidak
dapat dipalsukan. Lihat
[ADR 0011](../keputusan/0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md).

## Periode

Dua kolom terpisah dari `opname_date`: `period_type` (enum delapan case —
Triwulan I–IV, Semester I–II, Tahunan, Insidental) dan `period_year`. Judul
dokumen terbentuk sendiri, mis. **"Stok Opname Triwulan III 2026"**, dan judul
yang sama dipakai sebagai `reference_number` pada mutasi penyesuaian. Lihat
[ADR 0012](../keputusan/0012-periode-opname-enum-jenis-dan-tahun.md).

Dua opname pada periode yang sama tidak dilarang.

## Laporan

Berkas Excel satu lembar, dibangun `App\Support\LaporanStokOpname` dengan
pembantu bersama `App\Support\LembarExcel` (juga dipakai ekspor usulan
pengadaan): kop dan nama lembaga, identitas dokumen, rekap (Jumlah Barang,
Diisi Petugas, Mengikuti Stok Sistem, Barang Berselisih, Selisih Lebih/Kurang),
tabel rincian (Stok Sistem, Stok Fisik, Selisih, Penyesuaian, Stok Saat Posting,
Pencacahan, Alasan, Keterangan) dengan judul kolom beku dan dapat disaring,
dan catatan penjelas bila perlu. Sel Stok Saat Posting
berwarna bila stok bergerak setelah dicacah. Nama berkas
`laporan-opname-{tahun}-{periode}.xlsx`. Lihat
[ADR 0055](../keputusan/0055-laporan-stok-opname-dalam-excel.md).

## Notifikasi

- **Draf Opname Menggantung** (keadaan): draf yang belum diposting lebih dari 7
  hari, untuk pembuat + Admin.
- **Opname Diposting** (peristiwa): ringkasan posting untuk Gudang + Admin.
- **Stok Bergerak Setelah Dicacah** (peristiwa): hanya baris yang diisi petugas.

"Opname Siap Diposting" dihapus sejak ADR 0056.

## Perbaikan atas activity diagram

| Temuan pada diagram | Perbaikan |
|---|---|
| "Membuat Dokumen Laporan" bercabang paralel dengan pengecekan selisih, sehingga dokumen jadi **sebelum** alasan dimasukkan | Laporan dibuat setelah posting, dapat diunduh ulang kapan saja |
| Tidak ada keadaan draf; diagram menyiratkan sekali jalan | Draf yang dapat disimpan sebagian |
| Tidak ada konsep periode, padahal laporan diminta per triwulan | Kolom periode terstruktur pada `stock_opnames` |
| Tidak terlihat risiko stok berubah antara pencacahan dan posting | Selisih hasil cacah diterapkan di dalam kunci transaksi; baris yang berubah ditandai |

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Support/OpnamePoster.php](../../app/Support/OpnamePoster.php) | Penerapan hasil opname ke stok, termasuk mengisi barang yang tidak diisi |
| [app/Support/LaporanStokOpname.php](../../app/Support/LaporanStokOpname.php) | Laporan Stok Opname (.xlsx) |
| [app/Support/LembarExcel.php](../../app/Support/LembarExcel.php) | Trait pembantu laporan Excel, bersama ekspor usulan pengadaan |
| [app/Exceptions/OpnameTidakSiapException.php](../../app/Exceptions/OpnameTidakSiapException.php) | Syarat posting yang tidak terpenuhi |
| [app/Enums/PeriodeOpname.php](../../app/Enums/PeriodeOpname.php) | Jenis periode |
| [app/Enums/AlasanSelisih.php](../../app/Enums/AlasanSelisih.php) | Alasan selisih baku |
| [app/Enums/OpnameStatus.php](../../app/Enums/OpnameStatus.php) | Draf / Selesai |
| [app/Models/StockOpname.php](../../app/Models/StockOpname.php) | `judul()`, `ringkasan()`, `scopeFilter()` |
| [app/Models/OpnameDetail.php](../../app/Models/OpnameDetail.php) | `sudahDicacah()`, `diisiPetugas()`, `mengikutiStokSistem()`, `adaSelisih()`, `stokBerubahSejakDicacah()` |
| [app/Http/Controllers/Gudang/StockOpnameController.php](../../app/Http/Controllers/Gudang/StockOpnameController.php) | Delapan aksi modul; posting lewat `update()` |
| [app/Http/Requests/Gudang/StoreOpnameRequest.php](../../app/Http/Requests/Gudang/StoreOpnameRequest.php) | Validasi pembuatan draf |
| [app/Http/Requests/Gudang/UpdateOpnameCountRequest.php](../../app/Http/Requests/Gudang/UpdateOpnameCountRequest.php) | Validasi hasil cacah; membuka JSON `hasil_cacah` |
| [resources/views/pages/opname/](../../resources/views/pages/opname/) | `index`, `create`, `edit`, `show` |
| [database/seeders/StockOpnameSeeder.php](../../database/seeders/StockOpnameSeeder.php) | Satu opname selesai + satu draf berjalan |

## Data contoh

`StockOpnameSeeder` menghasilkan satu opname **Triwulan II 2026 berstatus
selesai** — delapan barang diisi (tiga berselisih beralasan, lima sesuai),
sisanya dikosongkan sehingga mengikuti stok sistem — dan satu **draf Triwulan
III 2026** yang baru diisi lima barang. Keduanya melalui `OpnamePoster` dan
`StockLedger` supaya mutasi penyesuaiannya konsisten dengan buku besar.

## Keterbatasan

- Nomor dokumen resmi belum ada. `reference_number` pada mutasi penyesuaian
  sementara memakai judul opname. Bila BPK memiliki format penomoran baku untuk
  berita acara opname, kolom nomor dokumen dapat ditambahkan menyusul — lihat
  butir terbuka pada [penyelarasan-erd.md](../erd/penyelarasan-erd.md).
- Sistem tidak dapat membedakan barang yang dicek dan cocok dari barang yang
  terlewat: memposting adalah pernyataan petugas bahwa yang kosong sesuai.
  Layar hasil dan laporan menandai setiap baris "Ikut sistem" supaya pembaca
  tahu angka mana yang diketik dan mana yang diasumsikan.
