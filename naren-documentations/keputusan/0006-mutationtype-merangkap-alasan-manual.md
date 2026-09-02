# ADR 0006 — `MutationType` merangkap alasan mutasi manual

## Status

Diterima — 3 September 2026

## Konteks

Sebagian pergerakan stok tidak berasal dari dokumen mana pun: barang rusak,
hilang, kadaluarsa, hibah masuk, retur dari unit kerja, atau koreksi pencatatan.
Pergerakan ini perlu dapat dicatat, tetapi tidak boleh menjadi celah kontrol —
stok yang berubah tanpa alasan terstruktur sulit dipertanggungjawabkan.

Awalnya `MutationType` hanya memuat empat nilai yang berasal dari proses sistem:
`SaldoAwal`, `Masuk`, `Keluar`, `Penyesuaian`.

## Keputusan

Menjadikan alasan mutasi manual sebagai **case tambahan pada `MutationType`**,
bukan kolom terpisah:

| Case | Arah | Asal |
|---|---|---|
| `SaldoAwal`, `Masuk`, `Keluar`, `Penyesuaian` | +, +, −, ± | Dokumen atau proses sistem |
| `HibahMasuk`, `ReturDariUnit` | + | Manual |
| `BarangRusak`, `BarangHilang`, `Kadaluarsa` | − | Manual |
| `KoreksiPencatatan` | ± | Manual |

Ditambah `isManual()` untuk memisahkan keduanya. Formulir mutasi manual hanya
menawarkan case bertanda manual, dan **menolak** jenis milik dokumen — supaya
tidak ada mutasi yang seolah-olah lahir dari pengadaan, permintaan, atau opname.

## Alternatif yang ditolak

**Kolom `reason` terpisah.** Menambah kolom kelima yang hanya terisi pada
sebagian baris, dan membuat kolom Jenis Mutasi pada layar riwayat menjadi tidak
informatif: seluruh mutasi rusak, hilang, dan kadaluarsa akan tampil sama-sama
sebagai Barang Keluar. Rekap per alasan juga memerlukan penyaringan dua kolom.

**Keterangan bebas tanpa daftar alasan.** Paling fleksibel, tetapi mustahil
direkap dan lemah secara kontrol — pemeriksa tidak dapat bertanya berapa banyak
barang hilang tahun ini tanpa membaca satu per satu.

**Meniadakan mutasi manual sepenuhnya.** Paling ketat, tetapi barang rusak atau
hilang baru dapat dikoreksi saat stock opname berikutnya, dan selama modul lain
belum ada, stok tidak dapat diuji bergerak.

## Konsekuensi

- Enum bertumbuh dari 4 menjadi 10 case, tetapi tanpa kolom tambahan.
- Kolom Jenis Mutasi langsung bermakna, dan penyaringnya cukup satu dropdown.
- Menambah alasan baru cukup menambah satu case beserta arahnya.
- Formulir manual perlu penjagaan eksplisit terhadap `isManual()`, karena
  validasi enum saja akan meloloskan `masuk` dan `keluar`.
