# Modul Stok Opname

Pencacahan fisik persediaan per periode, penyesuaian stok atas selisih yang
ditemukan, dan **Laporan Stok Opname** sebagai dokumen pertanggungjawaban.

Modul terakhir sub sistem manajemen gudang. Seluruh penyesuaiannya mengalir
lewat `StockLedger`, sehingga hasil opname tampil sebagai baris **Penyesuaian
Opname** di riwayat mutasi dan menunjuk balik ke dokumen opname-nya.

## Rute

Seluruhnya di bawah `middleware('can:kelola-gudang')` — Pengelola Gudang dan
Admin. Pejabat Pengadaan menerima 403 dan menunya tidak muncul.

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/opname` | `opname.index` | Daftar + 3 penyaring + kartu rekap |
| GET | `/opname/create` | `opname.create` | Formulir mulai opname |
| POST | `/opname` | `opname.store` | Buat draf + snapshot seluruh barang aktif |
| GET | `/opname/{opname}` | `opname.show` | Hasil opname dan rincian |
| GET | `/opname/{opname}/edit` | `opname.edit` | Layar pencacahan — draf saja |
| PUT | `/opname/{opname}` | `opname.update` | Simpan hasil cacah, boleh sebagian |
| POST | `/opname/{opname}/posting` | `opname.post` | Terapkan penyesuaian, kunci dokumen |
| GET | `/opname/{opname}/cetak` | `opname.pdf` | Laporan Stok Opname |
| DELETE | `/opname/{opname}` | `opname.destroy` | Hapus **draf saja** |

## Alur

```
Draf  ──simpan hasil cacah (berkali-kali)──►  Draf
  │
  └──posting (wajib lengkap + beralasan)──►  Selesai  ──►  cetak laporan
```

**Draf.** Dibuat dengan menyalin seluruh barang aktif beserta stok tercatatnya ke
`opname_details.stock_stored`. Barang yang didaftarkan setelah draf dibuat tidak
ikut — opname mencakup barang yang ada saat pencacahan dimulai. Draf dapat
disimpan sebagian dan dapat dihapus, karena belum menyentuh stok sama sekali.

Sejak 16 September 2026 ([ADR 0034](../keputusan/0034-log-aktivitas-terstruktur.md))
**tiap penyimpanan hasil cacah menulis satu baris `audit_logs`**, lengkap
dengan kemajuan pencacahannya ("7 dari 20 barang tercacah") supaya pengisian
awal dapat dibedakan dari koreksi menjelang posting. Sebelumnya hanya pembuatan
draf, pemostingan, dan penghapusan yang berjejak — padahal angka hasil cacah
fisik itulah yang menjadi dasar penyesuaian stok.

**Posting** ditolak bila salah satu tidak terpenuhi:

1. Dokumen masih berstatus draf;
2. Seluruh barang sudah dicacah;
3. Setiap baris berselisih memiliki alasan **dan** keterangan.

**Selesai.** Dokumen final: tidak dapat diubah, dihapus, maupun diposting ulang.
Penyesuaiannya sudah menjadi bagian buku mutasi yang immutable
([ADR 0005](../keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)).
Laporan dibuat setelah posting dan dapat dicetak ulang kapan saja.

Lihat [ADR 0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md).

## Lima angka pada satu baris rincian

| Kolom | Arti |
|---|---|
| `stock_stored` | Stok tercatat **saat draf dibuat** |
| `stock_real` | Hasil cacah fisik |
| `difference` | `stock_real − stock_stored` — selisih yang teramati saat mencacah |
| `stock_at_posting` | Stok tercatat **pada detik posting** |
| `adjustment_amount` | Mutasi yang benar-benar diterapkan = `difference` |

`stock_at_posting ≠ stock_stored` berarti stok bergerak setelah barang dicacah.
Baris itu ditandai di layar hasil dan di PDF.

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

## Alasan selisih

Enam alasan baku **berpasangan** keterangan bebas, keduanya wajib untuk setiap
baris yang berselisih: Barang Rusak, Barang Hilang, Kadaluarsa, Kesalahan
Pencatatan, Transaksi Belum Dibukukan, Lainnya.

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

PDF lanskap A4 memakai infrastruktur yang sama dengan modul mutasi
([ADR 0009](../keputusan/0009-laporan-pdf-dan-csv.md)): kop teks lembaga, judul
periode, identitas dokumen, kartu rekap, tabel rincian beserta alasan dan
keterangan, ruang tanda tangan, serta waktu cetak dan nama pencetak.

Baris yang stoknya berubah sejak dicacah diberi catatan kecil di bawah nama
barang, dan kalimat penjelas muncul di bawah tabel bila ada baris semacam itu.

## Perbaikan atas activity diagram

| Temuan pada diagram | Perbaikan |
|---|---|
| "Membuat Dokumen Laporan" bercabang paralel dengan pengecekan selisih, sehingga dokumen jadi **sebelum** alasan dimasukkan | Dokumen dibuat setelah posting, dapat dicetak ulang kapan saja |
| Tidak ada keadaan draf; diagram menyiratkan sekali jalan | Draf yang dapat disimpan sebagian |
| Tidak ada konsep periode, padahal laporan diminta per triwulan | Kolom periode terstruktur pada `stock_opnames` |
| Tidak terlihat risiko stok berubah antara pencacahan dan posting | Selisih hasil cacah diterapkan di dalam kunci transaksi; baris yang berubah ditandai |

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Support/OpnamePoster.php](../../app/Support/OpnamePoster.php) | Penerapan hasil opname ke stok |
| [app/Exceptions/OpnameTidakSiapException.php](../../app/Exceptions/OpnameTidakSiapException.php) | Tiga syarat posting yang tidak terpenuhi |
| [app/Enums/PeriodeOpname.php](../../app/Enums/PeriodeOpname.php) | Jenis periode |
| [app/Enums/AlasanSelisih.php](../../app/Enums/AlasanSelisih.php) | Alasan selisih baku |
| [app/Enums/OpnameStatus.php](../../app/Enums/OpnameStatus.php) | Draf / Selesai |
| [app/Models/StockOpname.php](../../app/Models/StockOpname.php) | `judul()`, `ringkasan()`, `siapDiposting()`, `scopeFilter()` |
| [app/Models/OpnameDetail.php](../../app/Models/OpnameDetail.php) | `sudahDicacah()`, `adaSelisih()`, `stokBerubahSejakDicacah()` |
| [app/Http/Controllers/Gudang/StockOpnameController.php](../../app/Http/Controllers/Gudang/StockOpnameController.php) | Sembilan aksi modul |
| [app/Http/Requests/Gudang/StoreOpnameRequest.php](../../app/Http/Requests/Gudang/StoreOpnameRequest.php) | Validasi pembuatan draf |
| [app/Http/Requests/Gudang/UpdateOpnameCountRequest.php](../../app/Http/Requests/Gudang/UpdateOpnameCountRequest.php) | Validasi hasil cacah massal |
| [resources/views/pages/opname/](../../resources/views/pages/opname/) | `index`, `create`, `edit`, `show` |
| [resources/views/pdf/laporan-opname.blade.php](../../resources/views/pdf/laporan-opname.blade.php) | Laporan Stok Opname |
| [database/seeders/StockOpnameSeeder.php](../../database/seeders/StockOpnameSeeder.php) | Satu opname selesai + satu draf berjalan |

## Data contoh

`StockOpnameSeeder` menghasilkan satu opname **Triwulan II 2026 berstatus
selesai** dengan beberapa selisih beralasan, dan satu **draf Triwulan III 2026**
yang baru tercacah sebagian. Keduanya melalui `OpnamePoster` dan `StockLedger`
supaya mutasi penyesuaiannya konsisten dengan buku besar.

## Keterbatasan

Nomor dokumen resmi belum ada. `reference_number` pada mutasi penyesuaian
sementara memakai judul opname. Bila BPK memiliki format penomoran baku untuk
berita acara opname, kolom nomor dokumen dapat ditambahkan menyusul — lihat butir
terbuka pada [penyelarasan-erd.md](../erd/penyelarasan-erd.md).
