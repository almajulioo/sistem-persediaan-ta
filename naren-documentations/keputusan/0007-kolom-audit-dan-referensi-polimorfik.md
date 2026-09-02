# ADR 0007 — Kolom audit tambahan dan referensi dokumen polimorfik

## Status

Diterima — 3 September 2026

## Konteks

Rancangan `stock_mutations` pada ERD memuat `mutation_id`, `item_id`,
`mutation_type`, `amount`, `mutation_date`, `reference`, dan `reference_type`.
Saat layar riwayat dibangun, empat kekurangan terlihat:

1. Tidak ada penanda **siapa** yang mencatat mutasi.
2. Tidak ada **saldo berjalan**, sehingga riwayat tidak dapat dibaca sebagai
   kartu persediaan yang tertelusuri baris demi baris.
3. `reference` bertipe UUID — tidak layak ditampilkan kepada pengguna, padahal
   rancangan antarmuka menuntut nomor dokumen yang terbaca.
4. Tidak ada tempat menuliskan alasan untuk mutasi manual.

## Keputusan

**Empat kolom tambahan** (improvisasi dari ERD, disetujui pengguna):

| Kolom | Alasan |
|---|---|
| `user_id` | Pencatat mutasi. Nullable karena baris seeder/sistem tidak punya pencatat, tetapi `restrictOnDelete` supaya jejak tidak hilang saat akun dihapus |
| `stock_after` | Saldo setelah mutasi. Menghitungnya saat tampil tidak andal karena data dipaginasi dan disaring |
| `reference_number` | Nomor dokumen resmi yang disalin permanen, sehingga laporan lama tetap menampilkan nomor yang sama meski dokumennya kelak berubah |
| `notes` | Keterangan; wajib untuk mutasi manual |

**Kolom `reference` diganti nama menjadi `reference_id`** agar cocok dengan
konvensi `morphTo` Laravel, dengan alias jenis dokumen terdaftar pada morph map
di `AppServiceProvider`: `pengadaan`, `permintaan`, `opname`.

`StockLedger` **menurunkan sendiri** `reference_type` dari
`$reference->getMorphClass()`; pemanggil tidak lagi mengirimnya. Ini
menghilangkan kemungkinan `reference_type` tidak cocok dengan model yang
ditunjuk.

## Alternatif yang ditolak

**Mempertahankan nama `reference` sesuai ERD.** Setiap kali dokumen sumber ingin
ditampilkan, perlu percabangan manual berdasarkan `reference_type` di banyak
tempat. Penyimpangan ini dicatat agar gambar ERD diperbarui.

**`Relation::enforceMorphMap` alih-alih `morphMap`.** Bentuk tegasnya memaksa
seluruh relasi polimorfik di aplikasi terdaftar, termasuk yang nanti dibuat
Alma, pengembang sub sistem permintaan. Terlalu mengikat untuk repositori berdua.

**Mengandalkan `audit_logs` untuk mengetahui pencatat.** Menghubungkan baris
audit ke baris mutasi tertentu rapuh; kartu persediaan seharusnya dapat dibaca
berdiri sendiri.

**Menyimpan hanya `reference_id` tanpa `reference_number`.** Nomor dokumen akan
selalu diambil dari dokumen aslinya, sehingga laporan lama ikut berubah bila
dokumen disunting — tidak dapat diterima untuk arsip.

## Konsekuensi

- `User::RESTRICTED_RELATIONS` bertambah `stockMutations`, sehingga akun yang
  pernah mencatat mutasi tidak dapat dihapus.
- Migrasi penambahan `stock_after` harus mengisi ulang baris lama dengan saldo
  berjalan, kalau tidak baris lama menampilkan 0.
- Kolom Referensi belum menjadi tautan karena modul dokumennya belum ada; relasi
  polimorfik inilah yang membuatnya mungkin nanti tanpa percabangan manual.
- Format nomor dokumen resmi BPK masih harus dikonfirmasi; nomor pada data contoh
  bersifat sementara.
