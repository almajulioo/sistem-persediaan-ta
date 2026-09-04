# Ikhtisar Sistem

## Tujuan

Sistem Informasi Persediaan untuk **BPK Perwakilan Provinsi Lampung**: mengelola
persediaan barang mulai dari permintaan unit kerja, pengadaan, hingga
penatausahaan stok di gudang. Sistem bersifat **tertutup** — dipakai di
lingkungan internal, tanpa pendaftaran mandiri, akun dibuat oleh Admin.

## Pembagian sub sistem

Repositori dikerjakan **dua orang**, menghasilkan **dua skripsi terpisah dengan
judul berbeda** sesuai sub sistem masing-masing:

| Pengembang | Sub sistem | Cakupan |
|---|---|---|
| **Narendra** | Pengadaan & Manajemen Gudang | Master barang, mutasi stok, stock opname, usulan & realisasi pengadaan |
| **Alma** | Permintaan Barang | Pengajuan permintaan unit kerja, verifikasi, penyerahan |

Karena itu **ERD keduanya berbeda, dan itu memang benar**. Yang perlu diselaraskan
hanya tabel yang dipakai kedua sub sistem — lihat
[peta kepemilikan tabel](erd/penyelarasan-erd.md). Dokumentasi pun dipisah:
folder ini milik Narendra, Alma memelihara dokumentasinya sendiri.

**Tabel bersama** — `items`, `stock_mutations`, `users`, `audit_logs` — hanya
diubah lewat kesepakatan kedua pihak. Migrasi dari dua orang pada tabel yang sama
rawan bentrok saat merge.

Titik integrasi utama adalah `App\Support\StockLedger`: sub sistem permintaan
memanggilnya untuk mengurangi stok saat barang diserahkan. Lihat
[ADR 0004](keputusan/0004-stok-hanya-lewat-stockledger.md).

## Peran pengguna

| Peran | Nilai enum | Kewenangan |
|---|---|---|
| **Admin** | `admin` | Tertinggi. Lolos seluruh pemeriksaan otorisasi lewat `Gate::before`, termasuk manajemen pengguna |
| **Pengelola Gudang** | `pengelola_gudang` | Master barang, mutasi stok, stock opname, konfirmasi penerimaan barang, penyerahan atas permintaan |
| **Pejabat Pengadaan** | `pejabat_pengadaan` | Usulan dan realisasi pengadaan |

Didefinisikan pada [app/Enums/UserRole.php](../app/Enums/UserRole.php).

Catatan: peran pemohon/pegawai untuk sub sistem permintaan belum ada di enum.
Bila diperlukan, penambahannya harus disepakati kedua pengembang karena `users`
adalah tabel bersama.

## Tumpukan teknologi

| Lapisan | Pilihan |
|---|---|
| Kerangka kerja | Laravel 12, PHP 8.2+ |
| Tampilan | Blade + Alpine.js, template TailAdmin (Tailwind CSS v4) |
| Basis data | MySQL/MariaDB (XAMPP saat pengembangan), SQLite in-memory untuk pengujian |
| Autentikasi | Sesi bawaan Laravel, tanpa starter kit — [ADR 0001](keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md) |
| Laporan | `barryvdh/laravel-dompdf` untuk PDF, CSV memakai kode bawaan — [ADR 0009](keputusan/0009-laporan-pdf-dan-csv.md) |
| Pengujian | Pest 4 |
| Gaya kode | Laravel Pint |

## Prinsip yang dipegang

Sistem ini dipakai lembaga audit, sehingga beberapa prinsip diperlakukan sebagai
syarat, bukan preferensi:

1. **Setiap perubahan stok punya jejak.** Tidak ada jalan mengubah `stock_current`
   tanpa baris di `stock_mutations`.
2. **Jejak audit tidak dapat diubah.** Baris mutasi tidak pernah disunting atau
   dihapus; kesalahan dikoreksi dengan mutasi lawan.
3. **Pemisahan tugas.** Pihak yang mengadakan barang bukan pihak yang menyatakan
   barang itu masuk gudang.
4. **Data yang sudah bertransaksi tidak dihapus.** Akun dan barang diarsipkan
   agar riwayat tetap utuh.

## Struktur kode yang relevan

```
app/
├── Enums/              UserRole, SatuanBarang, MutationType, StockStatus,
│                       PeriodeOpname, AlasanSelisih, OpnameStatus
├── Support/            StockLedger (kontrak lintas sub sistem), AuditLogger,
│                       OpnamePoster
├── Exceptions/         InsufficientStockException, OpnameTidakSiapException
├── Http/
│   ├── Controllers/
│   │   ├── Auth/       AuthenticatedSessionController
│   │   ├── Admin/      UserController
│   │   └── Gudang/     ItemController, StockMutationController,
│   │                   StockOpnameController
│   ├── Middleware/     EnsureUserIsActive, EnsureUserHasRole
│   └── Requests/       Auth/, Admin/, Gudang/
└── Models/             User, Item, StockMutation, StockOpname, OpnameDetail,
                        dan model warisan ERD

resources/views/
├── pages/              auth, dashboard, users, items, mutations, opname
├── components/         form/, ui/ (dipakai ulang seluruh modul)
├── pdf/                templat laporan cetak
└── vendor/pagination/  paginasi bergaya TailAdmin
```
