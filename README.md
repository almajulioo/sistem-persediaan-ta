# Sistem Informasi Persediaan — BPK Perwakilan Provinsi Lampung

Sistem pengelolaan persediaan barang: permintaan unit kerja, pengadaan, dan
penatausahaan stok gudang. Laravel 12 + Blade + Alpine.js di atas template
TailAdmin. Sistem tertutup — tidak ada pendaftaran mandiri, akun dibuat Admin.

Berkas ini hanya memuat hal yang diperlukan untuk bekerja bersama. Dokumentasi
pengembangan ada di folder masing-masing pengembang.

## Pembagian tugas

Repositori ini dikerjakan berdua dan menghasilkan **dua skripsi terpisah dengan
judul berbeda**, satu untuk tiap sub sistem.

| Pengembang | Sub sistem | Dokumentasi |
|---|---|---|
| **Narendra** | Pengadaan & Manajemen Gudang | [`naren-documentations/`](naren-documentations/) |
| **Alma** | Permintaan Barang | folder terpisah milik Alma |

**ERD kedua sub sistem berbeda, dan itu memang benar.** Yang perlu diselaraskan
hanya tabel yang dipakai bersama.

### Tabel bersama — hanya diubah lewat kesepakatan

`users`, `items`, `stock_mutations`, `audit_logs`

Bila tabel-tabel ini muncul di kedua ERD, kolomnya harus digambar sama persis.
Sumber kebenarannya adalah migrasi yang berjalan, bukan gambar di naskah. Migrasi
dari dua orang pada tabel yang sama rawan bentrok saat merge — peta kepemilikan
lengkapnya di
[penyelarasan-erd.md](naren-documentations/erd/penyelarasan-erd.md).

## Menjalankan proyek

Butuh PHP 8.2+, Composer, Node.js 18+, dan MySQL/MariaDB.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# buat database sesuai DB_DATABASE di .env, lalu:
php artisan migrate --seed
composer run dev
```

Akun awal di lingkungan lokal, kata sandi `Persediaan#2026`:

| Username | Peran |
|---|---|
| `admin` | Admin |
| `gudang` | Pengelola Gudang |
| `pengadaan` | Pejabat Pengadaan |

## ⚠️ Aturan wajib: seluruh perubahan stok lewat `StockLedger`

`items.stock_current` **tidak boleh** ditulis langsung — kolom itu sengaja
dikeluarkan dari `$fillable` pada `app/Models/Item.php`. Satu-satunya jalur sah
adalah `App\Support\StockLedger::record()`, yang menulis baris `stock_mutations`
dan menyesuaikan stok dalam satu transaksi berkunci.

Menulis `stock_current` langsung membuat buku mutasi dan kartu persediaan
menampilkan angka yang tidak sesuai stok sebenarnya — fatal untuk sistem di
lembaga audit.

**Contoh: penyerahan barang atas permintaan unit kerja**

```php
use App\Enums\MutationType;
use App\Support\StockLedger;

StockLedger::record(
    item: $item,
    type: MutationType::Keluar,
    amount: $jumlahDiserahkan,        // selalu positif; arah dari jenis mutasi
    recordedBy: $request->user(),
    notes: "Penyerahan ke {$unit} sesuai ND {$nomor}",
    reference: $permintaan,           // model dokumen sumber
    referenceNumber: $nomor,
);
```

Hal yang perlu diketahui:

- `reference_type` diturunkan sendiri dari morph map — **jangan** dikirim.
- Stok kurang melempar `App\Exceptions\InsufficientStockException`. Tangkap dan
  terjemahkan menjadi galat validasi, jangan biarkan menjadi 500.
- Seluruh argumen bersifat *named*, jadi penambahan parameter tidak merusak
  pemanggil yang ada. Jangan mengubah urutan atau menghapus parameter tanpa
  mengabari pengembang sub sistem lainnya.
- Baris `stock_mutations` **immutable**: tidak pernah diubah atau dihapus.
  Kesalahan dikoreksi dengan mutasi lawan berjenis `KoreksiPencatatan`.

Alasan lengkapnya di
[ADR 0004](naren-documentations/keputusan/0004-stok-hanya-lewat-stockledger.md).

## Kebiasaan kerja

**Rute yang juga boleh diakses Admin memakai `can:<gate>`, bukan `role:<peran>`.**
Middleware `role:` memeriksa peran secara harfiah dan akan menolak Admin dengan
403. Lihat
[ADR 0002](naren-documentations/keputusan/0002-otorisasi-peran-gate-dan-can.md).

**Jangan menjalankan Pint pada seluruh `app/Models` atau `database/migrations`.**
Berkas warisan ERD milik bersama akan ikut diformat ulang dan memicu konflik
merge. Batasi pada berkas yang memang sedang dikerjakan.

```bash
php artisan test                                  # seluruh uji
./vendor/bin/pint --test <direktori-yang-dikerjakan>
npm run build
```

---

Antarmuka memakai template [TailAdmin Laravel](https://github.com/TailAdmin/tailadmin-laravel) (MIT).
