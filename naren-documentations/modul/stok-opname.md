# Modul Stok Opname

Pencacahan fisik persediaan per periode, penyesuaian stok atas selisih yang
ditemukan, dan **Laporan Stok Opname** (Excel) sebagai dokumen
pertanggungjawaban.

Modul terakhir sub sistem manajemen gudang. Seluruh penyesuaiannya mengalir
lewat `StockLedger`, sehingga hasil opname tampil sebagai baris **Penyesuaian
Opname** di riwayat mutasi dan menunjuk balik ke dokumen opname-nya.

Sejak 9 November 2026 ([ADR 0057](../keputusan/0057-prosedur-lapangan-stok-opname.md)):

- opname berkala (triwulan, semester, tahunan) **wajib mengisi setiap barang**;
  hanya opname **insidental** yang barang kosongnya mengikuti stok sistem saat
  diposting ([ADR 0056](../keputusan/0056-barang-tidak-diisi-mengikuti-stok-sistem.md));
- hasil cacah dibandingkan dengan **stok sistem pada waktu barang dihitung** —
  jam "Dihitung Pada" dari lembar cacah — bukan dengan salinan saat draf dibuat;
- hanya **satu draf** boleh berjalan dalam satu waktu;
- laporannya berkas Excel ([ADR 0055](../keputusan/0055-laporan-stok-opname-dalam-excel.md)),
  ditutup ruang tanda tangan Kepala Subbagian Umum dan TI setelah diposting.

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
| GET | `/opname` | `opname.index` | Daftar + 3 penyaring + kartu rekap; "Lanjut Cacah" menggantikan "Mulai Opname" selama ada draf |
| GET | `/opname/create` | `opname.create` | Formulir mulai opname; selama ada draf, tombol "Lanjut Cacah" |
| POST | `/opname` | `opname.store` | Buat draf berisi seluruh barang aktif; ditolak selama ada draf |
| GET | `/opname/{opname}` | `opname.show` | Hasil opname dan rincian; penyaring `?baris=diisi\|berselisih\|tidak_diisi` |
| GET | `/opname/{opname}/edit` | `opname.edit` | Layar pencacahan — draf saja |
| PUT | `/opname/{opname}` | `opname.update` | Simpan baris yang berubah (+ `dihitung_pada`); dengan `aksi=posting` sekaligus posting |
| GET | `/opname/{opname}/excel` | `opname.excel` | Laporan Stok Opname (.xlsx) |
| DELETE | `/opname/{opname}` | `opname.destroy` | Buang **draf saja** — tombol "Buang Draf" di layar pencacahan dan halaman hasil; notifikasi milik draf ikut dibuang |

Rute `POST /opname/{opname}/posting` dan `GET /opname/{opname}/cetak` (PDF)
sudah tidak ada sejak ADR 0056 dan 0055.

## Alur

```
Draf  ──simpan baris yang berubah + "Dihitung Pada" (berkali-kali)──►  Draf
  │
  └──posting (isian di layar ikut tersimpan)──►  Selesai  ──►  unduh Excel
```

**Draf.** Dibuat dengan menyalin seluruh barang aktif ke `opname_details`.
Barang yang didaftarkan setelah draf dibuat tidak ikut — opname mencakup barang
yang ada saat pencacahan dimulai. Draf dapat disimpan sebagian dan dapat
dihapus, karena belum menyentuh stok sama sekali. **Hanya satu draf** boleh ada:
`store()` memeriksanya di dalam transaksi `KirimanFormulir::sekali()` — sesudah
pemeriksaan token, sehingga formulir yang terkirim dua kali tetap diarahkan ke
draf yang dibuatnya — dan menolak dengan arahan ke draf yang masih berjalan
(`DrafOpnameMasihAdaException`). Periode yang sama tetap boleh diopname
berulang, asal berurutan.

Setiap penyimpanan yang **benar-benar mengubah** baris menulis satu baris
`audit_logs`, lengkap dengan waktu cacah dan kemajuannya ("3 barang diubah,
dihitung pada 09 Nov 2026 08:30, 7 dari 120 barang diisi") supaya pengisian
awal dapat dibedakan dari koreksi menjelang posting
([ADR 0034](../keputusan/0034-log-aktivitas-terstruktur.md)). Kiriman tanpa
perubahan tidak dicatat.

**Posting** dikirim lewat `opname.update` dengan `aksi=posting`: isian di layar
disimpan dulu, lalu `OpnamePoster` dijalankan. Posting ditolak bila:

1. Dokumen bukan lagi draf;
2. Opname **bukan insidental** dan masih ada barang yang belum diisi ("Masih ada
   N barang yang belum diisi…");
3. Ada baris berselisih tanpa alasan **dan** keterangan.

Bila posting gagal, isian yang baru disimpan tetap ada di draf.

**Selesai.** Dokumen final: tidak dapat diubah, dihapus, maupun diposting ulang.
Penyesuaiannya sudah menjadi bagian buku mutasi yang immutable
([ADR 0005](../keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)).
Laporan dapat diunduh ulang kapan saja.

Lihat [ADR 0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md),
[ADR 0056](../keputusan/0056-barang-tidak-diisi-mengikuti-stok-sistem.md), dan
[ADR 0057](../keputusan/0057-prosedur-lapangan-stok-opname.md).

## Angka pada satu baris rincian

| Kolom | Arti |
|---|---|
| `stock_stored` | **Stok sistem pada waktu barang dihitung** — pembanding; "Stok Sistem" di layar. Saat draf dibuat hanya salinan awal, diganti ketika barisnya diisi |
| `stock_real` | Hasil cacah fisik; kosong = belum diisi |
| `counted_at` | Waktu "Dihitung Pada" untuk angka itu (dari lembar cacah); kosong = tidak diisi petugas |
| `difference` | `stock_real − stock_stored` — selisih yang teramati saat mencacah |
| `stock_at_posting` | Stok tercatat **pada detik posting** |
| `adjustment_amount` | Mutasi yang benar-benar diterapkan = `difference` |

`stock_at_posting ≠ stock_stored` pada baris yang **diisi petugas** berarti stok
bergerak setelah barang dihitung. Baris itu ditandai di layar hasil dan laporan.

`OpnameDetail::stokSistem()` adalah angka "Stok Sistem" yang ditampilkan:
pembanding untuk baris yang sudah dicacah, stok kini untuk yang belum.

## Waktu cacah ("Dihitung Pada")

Petugas mencacah di **lembar kertas** lalu mengetiknya kemudian. Karena itu
setiap simpan atau posting yang mengubah angka Stok Fisik wajib membawa
`dihitung_pada` — tanggal dan jam di lembar itu:

- tanpa isian bawaan, dan kosong lagi setelah setiap simpan;
- rentang sah: pukul 00:00 Tanggal Cacah sampai saat ini;
- mengosongkan baris atau mengubah alasan saja tidak memerlukannya.

Pembanding tiap angka baru = stok sistem pada menit itu, dihitung mundur dari
buku mutasi oleh `StockLedger::stokPada()`: stok kini dikurangi `amount` mutasi
yang tercatat sesudah menit itu. Mutasi yang tercatat **di dalam** menit itu
dihitung sudah terjadi. Pembanding dihitung ulang **hanya bila angka barisnya
berubah**; angka sama dengan jam lain tidak mengubah apa pun.

Contoh yang ditutup aturan ini: draf mencatat 5, pukul 10:15 tiga diserahkan
(sistem 2). Lembar bertanggal 08:30 berisi 5 → pembanding 5, selisih 0. Lembar
bertanggal 10:30 berisi 2 → pembanding 2, selisih 0. Dengan salinan saat draf
dibuat, angka 2 itu dulu menjadi "selisih −3" dan diterapkan dua kali.

Pembanding **tidak pernah diambil dari kiriman**. Layar menghitungnya sendiri
(data mutasi sejak 00:00 Tanggal Cacah disertakan per baris) dan mengirim
`stok_sistem` yang ditampilkannya; server menghitung ulang di dalam transaksi
dengan baris barang terkunci, dan menolak baris yang ternyata berbeda — ada
mutasi yang tercatat setelah halaman dibuka — dengan pesan yang menyebut angka
lama dan baru.

## Barang kosong pada opname insidental

`PeriodeOpname::bolehKosong()` — benar hanya untuk `Insidental` — adalah
satu-satunya tempat aturan ini. Saat posting opname insidental, baris yang
`stock_real`-nya kosong diisi `stock_stored = stock_real = stock_at_posting` dan
`difference = 0`: tidak ada mutasi, stok akhirnya tetap stok sistem pada detik
posting, dan "Stok Sistem" di laporan bukan salinan basi. `counted_at`
dibiarkan kosong — itulah penandanya:

| Metode `OpnameDetail` | Arti |
|---|---|
| `diisiPetugas()` | `counted_at` terisi |
| `mengikutiStokSistem()` | `stock_real` terisi, tetapi tidak diisi petugas |
| `stokBerubahSejakDicacah()` | Hanya baris yang diisi petugas |

`StockOpname::ringkasan()` memuat `barang`, `diisi`, `tidak_diisi`, `selisih`,
`lebih`, `kurang`. `kosongMengikutiStokSistem()` menentukan label `tidak_diisi`:
"Mengikuti/Ikut Stok Sistem" pada opname insidental yang selesai (atau opname
lama yang diposting di bawah ADR 0056 dengan baris kosong), selain itu "Belum
Diisi". `siapDiposting()` benar untuk draf selain insidental yang setiap
barangnya terisi.

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

- Isian **Dihitung Pada** (tanggal & jam) di samping Simpan Draf. Selama ada
  angka baru dan isian ini kosong, isiannya merah dan Simpan Draf terkunci.
- Kolom **Stok Sistem**: stok pada "Dihitung Pada" untuk angka baru (stok kini
  selama jamnya kosong), pembanding tersimpan untuk baris yang sudah dicacah;
  bila stok kini berbeda, ditampilkan di bawahnya ("kini 31"), dan jam cacah
  tersimpan menjadi tooltip data.
- Opname **berkala**: kotak Stok Fisik kosong tanpa placeholder; kartu dan chip
  **Belum Diisi**; modal posting menyebut "Belum diisi: N barang", kotak merah
  dengan tautan **Tampilkan**, dan "Ya, Posting" terkunci selama ada barang
  kosong.
- Opname **insidental**: kotak kosong ber-placeholder stok sistem, label **Ikut
  sistem**, kartu/chip **Ikut Stok Sistem**, satu kalimat petunjuk di atas tabel
  (permintaan Narendra, pengecualian aturan UI bersih), dan modal menyebut
  berapa barang mengikuti stok sistem.
- Kartu ringkasan berjalan: Total Barang, Diisi (sesuai · berselisih), Selisih
  Lebih / Kurang, Belum Diisi / Ikut Stok Sistem.
- Pencarian kode/nama; penyaring Semua / Diisi / Berselisih / Belum Diisi (atau
  Ikut Stok Sistem) / **Perlu Dilengkapi** (angka tidak sah, selisih tanpa
  alasan/keterangan, atau baris yang ditolak karena stok sistemnya berubah).
  Daftar disaring ulang hanya saat penyaring atau kata kunci berganti.
- Alasan dan keterangan muncul saat baris berselisih. **Enter** berpindah ke
  alasan → keterangan → baris berikutnya.
- "N perubahan belum disimpan", **Simpan Draf** nonaktif tanpa perubahan, dan
  peringatan peramban saat meninggalkan halaman dengan perubahan.
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
Pembandingnya dihitung server dari buku mutasi, tidak dibaca dari request,
supaya tidak dapat dipalsukan. Lihat
[ADR 0011](../keputusan/0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md).

## Periode

Dua kolom terpisah dari `opname_date`: `period_type` (enum delapan case —
Triwulan I–IV, Semester I–II, Tahunan, Insidental) dan `period_year`. Judul
dokumen terbentuk sendiri, mis. **"Stok Opname Triwulan III 2026"**, dan judul
yang sama dipakai sebagai `reference_number` pada mutasi penyesuaian. Lihat
[ADR 0012](../keputusan/0012-periode-opname-enum-jenis-dan-tahun.md).

Dua opname pada periode yang sama tidak dilarang, asal berurutan (satu draf
dalam satu waktu). Jenis periode juga menentukan apakah barang kosong boleh
diposting (hanya Insidental).

## Laporan

Berkas Excel satu lembar, dibangun `App\Support\LaporanStokOpname` dengan
pembantu bersama `App\Support\LembarExcel` (juga dipakai ekspor usulan
pengadaan): kop dan nama lembaga, identitas dokumen, rekap (Jumlah Barang,
Diisi Petugas, Belum Diisi / Mengikuti Stok Sistem, Barang Berselisih, Selisih
Lebih/Kurang), tabel rincian (Stok Sistem, Stok Fisik, Selisih, Penyesuaian,
Stok Saat Posting, Pencacahan, Alasan, Keterangan) dengan judul kolom beku dan
dapat disaring, dan catatan penjelas bila perlu. Sel Stok Saat Posting berwarna
bila stok bergerak setelah dicacah. Nama berkas
`laporan-opname-{tahun}-{periode}.xlsx`. Lihat
[ADR 0055](../keputusan/0055-laporan-stok-opname-dalam-excel.md).

Opname yang **sudah diposting** ditutup satu ruang tanda tangan di kolom
Pencacahan–Keterangan: "Bandar Lampung, {tanggal posting}", "Mengetahui,",
"Kepala Subbagian Umum dan TI", ruang kosong, lalu
"(....................................)". **Namanya ditulis tangan** — tidak
diambil dari akun mana pun. Draf tidak mendapatnya. Tidak ada tahap persetujuan
Kasubbag; posting tetap tindakan Pengelola Gudang
([ADR 0057](../keputusan/0057-prosedur-lapangan-stok-opname.md)).

## Notifikasi

- **Opname Siap Diposting** (keadaan): draf selain insidental yang setiap
  barangnya sudah diisi, untuk pembuatnya. Saling eksklusif dengan "menggantung";
  dipulihkan ADR 0057 setelah dihapus ADR 0056.
- **Draf Opname Menggantung** (keadaan): draf yang belum diposting lebih dari 7
  hari dan belum siap diposting, untuk pembuat + Admin.
- **Opname Diposting** (peristiwa): ringkasan posting untuk Gudang + Admin.
- **Stok Bergerak Setelah Dicacah** (peristiwa): hanya baris yang diisi petugas.

## Perbaikan atas activity diagram

| Temuan pada diagram | Perbaikan |
|---|---|
| "Membuat Dokumen Laporan" bercabang paralel dengan pengecekan selisih, sehingga dokumen jadi **sebelum** alasan dimasukkan | Laporan dibuat setelah posting, dapat diunduh ulang kapan saja |
| Tidak ada keadaan draf; diagram menyiratkan sekali jalan | Draf yang dapat disimpan sebagian |
| Tidak ada konsep periode, padahal laporan diminta per triwulan | Kolom periode terstruktur pada `stock_opnames` |
| Tidak terlihat risiko stok berubah antara pencacahan dan posting | Selisih hasil cacah diterapkan di dalam kunci transaksi; baris yang berubah ditandai |
| Tidak terlihat bahwa pencacahan berlangsung berhari-hari sementara gudang melayani | Pembanding = stok sistem pada jam di lembar cacah (ADR 0057) |

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Support/OpnamePoster.php](../../app/Support/OpnamePoster.php) | Penerapan hasil opname ke stok; syarat posting; baris kosong insidental |
| [app/Support/StockLedger.php](../../app/Support/StockLedger.php) | `stokPada()` — stok pada suatu menit menurut buku mutasi |
| [app/Support/LaporanStokOpname.php](../../app/Support/LaporanStokOpname.php) | Laporan Stok Opname (.xlsx), termasuk ruang tanda tangan |
| [app/Support/LembarExcel.php](../../app/Support/LembarExcel.php) | Trait pembantu laporan Excel, bersama ekspor usulan pengadaan |
| [app/Exceptions/OpnameTidakSiapException.php](../../app/Exceptions/OpnameTidakSiapException.php) | Syarat posting yang tidak terpenuhi |
| [app/Exceptions/DrafOpnameMasihAdaException.php](../../app/Exceptions/DrafOpnameMasihAdaException.php) | Draf baru ditolak selama ada draf |
| [app/Enums/PeriodeOpname.php](../../app/Enums/PeriodeOpname.php) | Jenis periode; `bolehKosong()` |
| [app/Enums/AlasanSelisih.php](../../app/Enums/AlasanSelisih.php) | Alasan selisih baku |
| [app/Enums/OpnameStatus.php](../../app/Enums/OpnameStatus.php) | Draf / Selesai |
| [app/Models/StockOpname.php](../../app/Models/StockOpname.php) | `judul()`, `bolehKosong()`, `siapDiposting()`, `kosongMengikutiStokSistem()`, `ringkasan()`, `scopeFilter()` |
| [app/Models/OpnameDetail.php](../../app/Models/OpnameDetail.php) | `sudahDicacah()`, `diisiPetugas()`, `mengikutiStokSistem()`, `adaSelisih()`, `stokBerubahSejakDicacah()`, `stokSistem()` |
| [app/Http/Controllers/Gudang/StockOpnameController.php](../../app/Http/Controllers/Gudang/StockOpnameController.php) | Delapan aksi modul; posting lewat `update()`; satu draf aktif |
| [app/Http/Requests/Gudang/StoreOpnameRequest.php](../../app/Http/Requests/Gudang/StoreOpnameRequest.php) | Validasi pembuatan draf |
| [app/Http/Requests/Gudang/UpdateOpnameCountRequest.php](../../app/Http/Requests/Gudang/UpdateOpnameCountRequest.php) | Validasi hasil cacah; JSON `hasil_cacah`; waktu cacah dan pembanding |
| [resources/views/pages/opname/](../../resources/views/pages/opname/) | `index`, `create`, `edit`, `show` |
| [database/seeders/StockOpnameSeeder.php](../../database/seeders/StockOpnameSeeder.php) | Satu opname selesai + satu draf berjalan |

## Data contoh

`StockOpnameSeeder` menghasilkan satu opname **Triwulan II 2026 berstatus
selesai** — setiap barang diisi, tiga berselisih beralasan, sisanya sesuai — dan
satu **draf Triwulan III 2026** yang baru diisi lima barang (sehingga belum dapat
diposting). Yang selesai diposting lewat `OpnamePoster` dan `StockLedger` supaya
mutasi penyesuaiannya konsisten dengan buku besar.

## Keterbatasan

- Nomor dokumen resmi belum ada. `reference_number` pada mutasi penyesuaian
  sementara memakai judul opname. Bila BPK memiliki format penomoran baku untuk
  berita acara opname, kolom nomor dokumen dapat ditambahkan menyusul — lihat
  butir terbuka pada [penyelarasan-erd.md](../erd/penyelarasan-erd.md).
- Ketepatan pembanding sebaik jam yang ditulis petugas di lembar cacah. Sistem
  membatasi rentangnya dan mencatat jam yang dipakai di log aktivitas, tetapi
  tidak dapat membuktikan jam itu benar.
- Pada opname insidental sistem tidak dapat membedakan barang yang dicek dan
  cocok dari barang yang terlewat: memposting adalah pernyataan petugas bahwa
  yang kosong sesuai. Layar hasil dan laporan menandai setiap baris "Ikut
  sistem" supaya pembaca tahu angka mana yang diketik dan mana yang diasumsikan.
