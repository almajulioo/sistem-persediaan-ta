# ADR 0004 — Stok hanya berubah lewat `StockLedger`

## Status

Diterima — 2 September 2026

## Konteks

`items.stock_current` adalah angka yang paling sering dilihat dan paling mudah
salah. Tanpa penjagaan, stok dapat diubah dari mana saja: formulir master barang,
modul pengadaan, modul permintaan, atau seeder. Bila itu terjadi, angka stok
tidak lagi cocok dengan buku mutasi, dan kartu persediaan menjadi bohong.

Risikonya meningkat karena repositori dikerjakan **dua orang**: sub sistem
permintaan milik Alma juga perlu mengurangi stok.

## Keputusan

1. `stock_current` **dikeluarkan dari `$fillable`** pada model `Item`. Mass
   assignment tidak dapat menyentuhnya, termasuk lewat data yang diselundupkan ke
   dalam request.
2. Satu-satunya jalur sah adalah `App\Support\StockLedger::record()`, yang dalam
   satu transaksi: mengunci baris barang dengan `lockForUpdate()`, menghitung
   stok baru, menolak hasil negatif, menulis baris `stock_mutations`, lalu
   memperbarui `stock_current`.
3. Antarmuka `StockLedger` diperlakukan sebagai **kontrak publik lintas sub
   sistem**: seluruh argumen bersifat *named* sehingga penambahan parameter tidak
   merusak pemanggil yang ada. Kontraknya diringkas di `README.md` root.
4. Stok tidak cukup melempar `InsufficientStockException`; pemanggil yang
   menerjemahkannya menjadi galat validasi yang ramah.

## Alternatif yang ditolak

**Observer atau event pada model `Item`.** Perubahan stok tetap bisa terjadi
lewat banyak jalur, dan pengunciannya sulit dijamin. Observer juga menyulitkan
penelusuran: sulit tahu siapa yang memicu perubahan.

**Menghitung stok on-the-fly dari jumlah mutasi.** Benar secara teori, tetapi
mahal untuk setiap tampilan daftar barang dan tidak dapat dikunci dengan mudah
saat transaksi bersamaan.

**Membiarkan `stock_current` fillable dengan kesepakatan tim.** Kesepakatan tidak
menghentikan kesalahan. Menutupnya di tingkat model membuat pelanggaran menjadi
mustahil, bukan sekadar tidak dianjurkan.

## Konsekuensi

- `ItemFactory` harus mengisi `stock_current` lewat `forceFill` dengan
  meng-*override* `newModel()`, karena factory menghormati `$fillable`.
- Formulir ubah barang tidak memuat kolom stok sama sekali; stok awal hanya dapat
  diisi sekali saat barang dibuat, dan tercatat sebagai mutasi Saldo Awal.
- Setiap modul baru yang menyentuh stok wajib memanggil `StockLedger`. Ini
  ditegaskan di docblock kelas dan di `README.md` agar ditemukan tanpa membaca
  kode.
