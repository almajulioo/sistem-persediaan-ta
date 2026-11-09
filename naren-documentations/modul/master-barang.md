# Modul Master Barang

Data induk barang persediaan — inti dari sub sistem gudang, karena seluruh modul
lain memiliki foreign key ke `items`.

## Rute

Rute yang mengubah data di bawah `middleware('can:kelola-gudang')`, sehingga
Pengelola Gudang **dan** Admin berhak. Lihat
[ADR 0002](../keputusan/0002-otorisasi-peran-gate-dan-can.md). Daftar, rincian,
dan kartu persediaan di bawah `can:akses-gudang`, yang juga meloloskan Kasubbag
Umum dan TI **baca-saja**: tombol Tambah/Ubah/Arsipkan/Hapus dan kolom Aksi
tidak tampil baginya ([ADR 0041](../keputusan/0041-kasubbag-pengawas-baca-saja.md)).

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
| GET | `/sub-kategori-barang` | `item-subcategories.index` | Daftar sub kategori + cari + saring kategori (baca: `akses-gudang`) |
| GET | `/sub-kategori-barang/create` | `item-subcategories.create` | Formulir tambah sub kategori |
| POST | `/sub-kategori-barang` | `item-subcategories.store` | Simpan sub kategori |
| GET | `/sub-kategori-barang/{subcategory}/edit` | `item-subcategories.edit` | Formulir ubah sub kategori |
| PUT | `/sub-kategori-barang/{subcategory}` | `item-subcategories.update` | Perbarui; kategori barangnya ikut berubah |
| DELETE | `/sub-kategori-barang/{subcategory}` | `item-subcategories.destroy` | Hapus bila belum dipakai barang |

`/barang` menerima penyaring `category` (`ATK`/`ARTK`) dan `subcategory`
(`subcategory_id`) di samping `q`, `unit`, `stock`, dan `status`.

`/barang/create?item_proposal_id=…` dipakai untuk mendaftarkan usulan barang
baru dari rincian permohonan. Penolakan usulan memakai
`PATCH /usulan-barang/{itemProposal}/tolak` — lihat
[modul Pengadaan](pengadaan.md#usulan-barang-baru).

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
  membutuhkan barang belum terdaftar memohonkannya lewat Permohonan
  ([ADR 0032](../keputusan/0032-usulan-barang-baru.md),
  [ADR 0053](../keputusan/0053-usulan-barang-baru-diputuskan-dari-permohonan.md)); yang paling bernilai
  dari langkah ini bukan pengisian formulirnya, melainkan pemeriksaan duplikat.

## Kategori dan sub kategori

Ditambahkan 3 November 2026 oleh Alma —
[ADR 0043](../keputusan/0043-kategori-dan-sub-kategori-barang.md).

- **Kategori** hanya dua, enum `KategoriBarang`: `ATK` (Alat Tulis Kantor) dan
  `ARTK` (Alat Rumah Tangga Kantor).
- **Sub kategori** ada di tabel `item_subcategories`, masing-masing berkategori
  satu. Kodenya mengikuti kodefikasi barang: sepuluh digit pertama kode barang
  kantor = kode sub kategorinya.
- Barang memilih **sub kategori**; `items.category` disalin otomatis darinya
  dan tidak dapat dipilih sendiri. Mengubah kategori sub kategori ikut
  memindahkan seluruh barangnya.
- Sub kategori yang masih dipakai barang tidak dapat dihapus.

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
| `subcategory_id` | Wajib, harus ada di `item_subcategories`. Kategori barang ikut darinya |
| `unit` | Wajib, salah satu `SatuanBarang` |
| `stock_min` | Wajib, bilangan bulat 0–999999 |
| `stock_max` | Wajib, bilangan bulat 0–999999, tidak boleh kurang dari `stock_min` |
| `stock_awal` | Hanya saat menambah; wajib, bilangan bulat >= 0 |
| `is_active` | Wajib, boolean |

Sub kategori: `subcategory_code` wajib, unik, maks 50, karakter sama dengan
kode barang, dinormalkan ke huruf besar; `subcategory_name` wajib, maks 255;
`category` wajib, salah satu `KategoriBarang`.

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
  Pengelola Gudang — hanya dia yang mengenal penamaan bakunya. Tombolnya
  ("Daftarkan") ada di rincian permohonan; Batal dan pengalihan setelah simpan
  kembali ke permohonan itu (`ItemProposal::tautan()`, ADR 0053).
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
| [app/Models/ItemSubcategory.php](../../app/Models/ItemSubcategory.php) | Sub kategori; merambatkan perubahan kategori ke barangnya (ADR 0043) |
| [app/Enums/KategoriBarang.php](../../app/Enums/KategoriBarang.php) | Kategori ATK / ARTK |
| [app/Http/Controllers/Gudang/ItemSubcategoryController.php](../../app/Http/Controllers/Gudang/ItemSubcategoryController.php) | Kelola sub kategori |
| [resources/views/pages/item-subcategories/](../../resources/views/pages/item-subcategories/) | `index`, `create`, `edit`, `_form` |
| [database/seeders/ItemSubcategorySeeder.php](../../database/seeders/ItemSubcategorySeeder.php) | 75 sub kategori dari `data/kategori_barang.csv` |
| [app/Enums/ItemProposalStatus.php](../../app/Enums/ItemProposalStatus.php) | Tiga status usulan barang |
| [app/Enums/SatuanBarang.php](../../app/Enums/SatuanBarang.php) | Daftar satuan |
| [app/Enums/StockStatus.php](../../app/Enums/StockStatus.php) | Status kecukupan stok |
| [app/Http/Controllers/Gudang/ItemController.php](../../app/Http/Controllers/Gudang/ItemController.php) | Seluruh aksi |
| [app/Http/Controllers/Gudang/ItemProposalController.php](../../app/Http/Controllers/Gudang/ItemProposalController.php) | Usulan barang baru: tolak saja (ADR 0053) |
| [app/Http/Requests/Gudang/](../../app/Http/Requests/Gudang/) | `StoreItemRequest`, `UpdateItemRequest`, `ItemFormRules` |
| [resources/views/pages/items/](../../resources/views/pages/items/) | `index`, `create`, `edit`, `show`, `_form` |
| [database/seeders/ItemSeeder.php](../../database/seeders/ItemSeeder.php) | 1.801 barang kantor dari `data/barang.csv` + saldo awal 120 barang dari `data/saldo_awal_2026_10_01.csv` |
| [database/seeders/ItemProposalSeeder.php](../../database/seeders/ItemProposalSeeder.php) | 3 usulan barang contoh |

## Catatan implementasi

`ItemFactory` meng-*override* `newModel()` dengan `forceFill` karena
`stock_current` tidak `fillable`. Tanpa itu, factory tidak dapat menyiapkan
skenario uji dengan stok tertentu.
