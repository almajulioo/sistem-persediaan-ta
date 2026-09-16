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

Rute usulan barang baru tidak ada di tabel ini karena gate-nya berbeda-beda —
lihat [modul Pengadaan](pengadaan.md#usulan-barang-baru).

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
- **Hanya Pengelola Gudang yang mendaftarkan barang.** Peran lain yang
  membutuhkan barang belum terdaftar mengusulkannya lewat Usulan Barang Baru
  ([ADR 0032](../keputusan/0032-usulan-barang-baru.md)); yang paling bernilai
  dari langkah ini bukan pengisian formulirnya, melainkan pemeriksaan duplikat.

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

## Usulan barang baru: pintu depan katalog

Ditambahkan 16 September 2026. Rincian keputusan:
[ADR 0032](../keputusan/0032-usulan-barang-baru.md); rute, notifikasi, dan
aturan lengkapnya di [modul Pengadaan](pengadaan.md#usulan-barang-baru).

Yang perlu diketahui dari sisi master barang:

- `items.create` menerima `?item_proposal_id=` dan **mengisi** `item_name` serta
  `unit` dari usulannya. Kode barang dan batas minimum/maksimum tetap diisi
  Pengelola Gudang — hanya dia yang mengenal penamaan bakunya.
- `items.store` menutup usulannya **di dalam transaksi yang sama** dengan
  pembuatan barang: mengisi `item_proposals.item_id`, `status = Disetujui`,
  `decided_by`, dan `decided_at`. Tidak ada jalur pembuatan barang kedua, dan
  tidak mungkin ada usulan `Disetujui` tanpa barang.
- Usulan yang sudah diputuskan ditolak di dua tempat — `create()` dan
  `StoreItemRequest::withValidator()` — karena formulirnya bisa dikirim ulang
  dari tab yang sudah lama terbuka.
- Barang hasil usulan lahir **berstok nol**, sehingga `StokHabis` langsung
  berlaku dan draf usulan otomatis menyedotnya. Bukan kerusakan; keduanya
  melepas sendiri begitu barangnya diterima gudang.
- `Item::RESTRICTED_RELATIONS` bertambah `itemProposals`, sehingga barang yang
  pernah lahir dari usulan ikut terlindung aturan arsip-alih-alih-hapus
  ([ADR 0003](../keputusan/0003-arsip-alih-alih-hapus.md)).

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Models/Item.php](../../app/Models/Item.php) | Cast, scope, `stockStatus()`, `blockingRelations()` |
| [app/Models/ItemProposal.php](../../app/Models/ItemProposal.php) | Usulan barang baru (ADR 0032) |
| [app/Enums/ItemProposalStatus.php](../../app/Enums/ItemProposalStatus.php) | Tiga status usulan barang |
| [app/Enums/SatuanBarang.php](../../app/Enums/SatuanBarang.php) | Daftar satuan |
| [app/Enums/StockStatus.php](../../app/Enums/StockStatus.php) | Status kecukupan stok |
| [app/Http/Controllers/Gudang/ItemController.php](../../app/Http/Controllers/Gudang/ItemController.php) | Seluruh aksi |
| [app/Http/Controllers/Gudang/ItemProposalController.php](../../app/Http/Controllers/Gudang/ItemProposalController.php) | Usulan barang baru: ajukan, baca, tolak |
| [app/Http/Requests/Gudang/](../../app/Http/Requests/Gudang/) | `StoreItemRequest`, `UpdateItemRequest`, `ItemFormRules` |
| [resources/views/pages/items/](../../resources/views/pages/items/) | `index`, `create`, `edit`, `show`, `_form` |
| [resources/views/pages/item-proposals/](../../resources/views/pages/item-proposals/) | `index`, `create`, `show` |
| [database/seeders/ItemSeeder.php](../../database/seeders/ItemSeeder.php) | 15 barang ATK/RTK contoh |
| [database/seeders/ItemProposalSeeder.php](../../database/seeders/ItemProposalSeeder.php) | 3 usulan barang contoh |

## Catatan implementasi

`ItemFactory` meng-*override* `newModel()` dengan `forceFill` karena
`stock_current` tidak `fillable`. Tanpa itu, factory tidak dapat menyiapkan
skenario uji dengan stok tertentu.
