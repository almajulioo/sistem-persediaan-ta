# Modul Master Barang

Data induk barang persediaan — inti dari sub sistem gudang, karena seluruh modul
lain memiliki foreign key ke `items`.

## Rute

Seluruhnya di bawah `middleware('can:kelola-gudang')`, sehingga Pengelola Gudang
**dan** Admin berhak. Lihat [ADR 0002](../keputusan/0002-otorisasi-peran-gate-dan-can.md).

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/barang` | `items.index` | Daftar + cari + 3 penyaring + ringkasan |
| GET | `/barang/create` | `items.create` | Formulir tambah |
| POST | `/barang` | `items.store` | Simpan + mutasi Saldo Awal |
| GET | `/barang/{item}` | `items.show` | Detail + riwayat mutasi |
| GET | `/barang/{item}/edit` | `items.edit` | Formulir ubah |
| PUT | `/barang/{item}` | `items.update` | Perbarui (tanpa menyentuh stok) |
| PATCH | `/barang/{item}/status` | `items.toggle-status` | Arsipkan / aktifkan |
| DELETE | `/barang/{item}` | `items.destroy` | Hapus |
| GET | `/barang/{item}/kartu-persediaan` | `items.kartu-persediaan` | Cetak PDF kartu persediaan |

## Aturan bisnis

- **Stok tidak dapat diubah lewat formulir.** `stock_current` dikeluarkan dari
  `$fillable`, sehingga tidak dapat diselundupkan lewat request. Stok awal hanya
  diisi sekali saat barang dibuat dan tercatat sebagai mutasi Saldo Awal. Lihat
  [ADR 0004](../keputusan/0004-stok-hanya-lewat-stockledger.md).
- **Barang bertransaksi tidak dapat dihapus**, hanya diarsipkan. Lihat
  [ADR 0003](../keputusan/0003-arsip-alih-alih-hapus.md).
- Barang yang diarsipkan tidak muncul pada pilihan transaksi baru.
- `stock_max = 0` berarti **tanpa batas atas** — barang tidak akan pernah
  berstatus Berlebih.

## Status stok

Nilai turunan, bukan kolom database:

| Status | Kondisi |
|---|---|
| Habis | `stock_current <= 0` |
| Menipis | `stock_current <= stock_min` |
| Berlebih | `stock_max > 0` dan `stock_current > stock_max` |
| Aman | Selain di atas |

## Validasi

| Field | Aturan |
|---|---|
| `item_code` | Wajib, maks 50, huruf besar/angka/titik/tanda hubung/garis miring, unik; dinormalkan ke huruf besar |
| `item_name` | Wajib, maks 255 |
| `unit` | Wajib, salah satu `SatuanBarang` |
| `stock_min` | Wajib, bilangan bulat 0–999999 |
| `stock_max` | Wajib, bilangan bulat 0–999999, tidak boleh kurang dari `stock_min` |
| `stock_awal` | Hanya saat menambah; wajib, bilangan bulat >= 0 |
| `is_active` | Wajib, boolean |

`UpdateItemRequest` sengaja **tidak memuat** `stock_awal` maupun `stock_current`.

## Satuan barang

Daftar tetap lewat enum untuk mencegah data kotor seperti "Buah" / "buah" / "bh"
yang mengacaukan rekap: Buah, Rim, Box, Pak, Dus, Lusin, Unit, Set, Botol,
Kilogram, Liter, Lembar.

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Models/Item.php](../../app/Models/Item.php) | Cast, scope, `stockStatus()`, `blockingRelations()` |
| [app/Enums/SatuanBarang.php](../../app/Enums/SatuanBarang.php) | Daftar satuan |
| [app/Enums/StockStatus.php](../../app/Enums/StockStatus.php) | Status kecukupan stok |
| [app/Http/Controllers/Gudang/ItemController.php](../../app/Http/Controllers/Gudang/ItemController.php) | Seluruh aksi |
| [app/Http/Requests/Gudang/](../../app/Http/Requests/Gudang/) | `StoreItemRequest`, `UpdateItemRequest`, `ItemFormRules` |
| [resources/views/pages/items/](../../resources/views/pages/items/) | `index`, `create`, `edit`, `show`, `_form` |
| [database/seeders/ItemSeeder.php](../../database/seeders/ItemSeeder.php) | 15 barang ATK/RTK contoh |

## Catatan implementasi

`ItemFactory` meng-*override* `newModel()` dengan `forceFill` karena
`stock_current` tidak `fillable`. Tanpa itu, factory tidak dapat menyiapkan
skenario uji dengan stok tertentu.
