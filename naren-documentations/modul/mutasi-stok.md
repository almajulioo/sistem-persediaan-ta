# Modul Mutasi Stok

Buku besar seluruh pergerakan stok, beserta pencatatan mutasi manual dan
pelaporannya. Merangkap infrastruktur laporan yang dipakai ulang modul lain.

## Rute

Seluruhnya di bawah `middleware('can:kelola-gudang')`.

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/mutasi` | `mutations.index` | Riwayat + 4 penyaring + rekap |
| GET | `/mutasi/create` | `mutations.create` | Formulir mutasi manual |
| POST | `/mutasi` | `mutations.store` | Simpan mutasi manual |
| GET | `/mutasi/cetak/pdf` | `mutations.pdf` | Laporan PDF |
| GET | `/mutasi/cetak/csv` | `mutations.csv` | Ekspor CSV |

**Tidak ada rute ubah maupun hapus** — mutasi bersifat immutable. Lihat
[ADR 0005](../keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md).

## Kolom riwayat

| Kolom | Sumber nilai |
|---|---|
| Kode & Nama Barang | Relasi `item` |
| Jenis Mutasi | `mutation_type`, sekaligus alasan bila manual |
| Sumber | Turunan: Pengadaan / Permintaan / Stock Opname / Saldo Awal / Manual |
| Jumlah | `amount` bertanda, hijau untuk masuk, merah untuk keluar |
| Stok Setelah | `stock_after` — saldo pada saat mutasi **dicatat** |
| Tanggal | `mutation_date` — tanggal transaksi, dapat berbeda dari waktu pencatatan |
| Referensi | `reference_number`, nomor dokumen resmi |
| Petugas | Nama pencatat, atau "Sistem" bila tidak ada |

Diurutkan menurut **waktu pencatatan** (`created_at`), bukan tanggal transaksi,
supaya kolom saldo berjalan koheren.

## Jenis mutasi

| Jenis | Arah | Asal | Boleh manual |
|---|---|---|---|
| Saldo Awal | + | Pembuatan barang | Tidak |
| Barang Masuk | + | Pengadaan | Tidak |
| Barang Keluar | − | Permintaan | Tidak |
| Penyesuaian Opname | ± | Stock opname | Tidak |
| Hibah Masuk | + | Manual | Ya |
| Retur dari Unit | + | Manual | Ya |
| Barang Rusak | − | Manual | Ya |
| Barang Hilang | − | Manual | Ya |
| Kadaluarsa | − | Manual | Ya |
| Koreksi Pencatatan | ± | Manual | Ya |

Lihat [ADR 0006](../keputusan/0006-mutationtype-merangkap-alasan-manual.md).

## Aturan mutasi manual

- Hanya jenis bertanda manual yang boleh dipilih. Jenis milik dokumen
  (`masuk`, `keluar`, `saldo_awal`, `penyesuaian`) **ditolak** supaya tidak ada
  mutasi yang seolah lahir dari pengadaan atau permintaan.
- Keterangan **wajib** diisi — menjadi bukti pertanggungjawaban saat pemeriksaan.
- Tanggal mutasi tidak boleh melebihi hari ini.
- Barang yang diarsipkan tidak dapat dipilih.
- Koreksi Pencatatan memerlukan pilihan arah (menambah atau mengurangi).
- Stok tidak mencukupi menghasilkan galat validasi pada kolom jumlah, bukan
  halaman 500, dan tidak menyisakan baris apa pun.

## Laporan

**PDF** berkop teks lembaga, memuat periode dan penyaring yang dipakai, tabel
rekap, tabel data, ruang tanda tangan, serta waktu cetak dan nama pencetak.

**CSV** ber-BOM UTF-8 dengan pemisah titik koma, agar terbuka rapi di Excel
lokal.

**Kartu Persediaan** per barang memakai infrastruktur PDF yang sama, ditautkan
dari halaman detail barang.

Ketiganya memakai **kueri penyaring yang sama** dengan layar, sehingga isi
laporan tidak mungkin berbeda dari yang dilihat pengguna. Ekspor dibatasi 5.000
baris. Lihat [ADR 0009](../keputusan/0009-laporan-pdf-dan-csv.md).

## `StockLedger` — kontrak lintas sub sistem

Satu-satunya jalur perubahan stok, dipakai bersama kedua sub sistem:

```php
StockLedger::record(
    item: $item,
    type: MutationType::Keluar,
    amount: $jumlah,                 // selalu positif; arah dari jenis mutasi
    recordedBy: $request->user(),
    notes: "Penyerahan ke {$unit} sesuai ND {$nomor}",
    reference: $permintaan,          // model dokumen sumber
    referenceNumber: $nomor,
    date: $tanggal,
);
```

`reference_type` diturunkan sendiri dari morph map — tidak dikirim pemanggil.
Seluruh argumen bersifat *named* supaya penambahan parameter tidak merusak
pemanggil yang ada. Lihat
[ADR 0004](../keputusan/0004-stok-hanya-lewat-stockledger.md) dan
[ADR 0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md).

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Support/StockLedger.php](../../app/Support/StockLedger.php) | Jalur tunggal perubahan stok |
| [app/Exceptions/InsufficientStockException.php](../../app/Exceptions/InsufficientStockException.php) | Stok tidak mencukupi |
| [app/Enums/MutationType.php](../../app/Enums/MutationType.php) | Jenis mutasi + alasan manual |
| [app/Models/StockMutation.php](../../app/Models/StockMutation.php) | `sumber()`, `petugas()`, `signedAmount()`, relasi `reference` |
| [app/Http/Controllers/Gudang/StockMutationController.php](../../app/Http/Controllers/Gudang/StockMutationController.php) | Riwayat, mutasi manual, laporan |
| [resources/views/pages/mutations/](../../resources/views/pages/mutations/) | `index`, `create` |
| [resources/views/pdf/](../../resources/views/pdf/) | `laporan-mutasi`, `kartu-persediaan`, `_style` |
| [database/seeders/StockMutationSeeder.php](../../database/seeders/StockMutationSeeder.php) | 60 mutasi contoh |

## Keterbatasan data contoh

`reference_id` masih kosong karena modul pengadaan dan permintaan belum ada,
sehingga belum ada baris dokumen untuk ditunjuk. `reference_type` tetap diisi agar
kolom Sumber dapat diuji. **Nomor dokumen pada data contoh masih perkiraan** —
format resmi BPK belum dikonfirmasi.
