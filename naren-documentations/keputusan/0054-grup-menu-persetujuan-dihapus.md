# ADR 0054 — Grup menu Persetujuan dihapus

## Status

Diterima — 9 November 2026

Menggantikan sebagian
[ADR 0030](0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md), bagian
"Tampilan: grup menu, bukan layar baru". Grup menunya hilang. Keputusan bahwa
persetujuan diambil dari halaman rincian realisasi, bukan dari layar
tersendiri, tetap berlaku.

## Konteks

ADR 0030 memberi Kasubbag Umum dan TI grup menu **Persetujuan** berisi satu
tautan, "Realisasi Pengadaan", ke `/pengadaan?status=menunggu_persetujuan`.
Admin ikut melihatnya.

Sejak [ADR 0041](0041-kasubbag-pengawas-baca-saja.md), Kasubbag juga melihat
grup **Pengadaan** (baca-saja), yang memuat "Realisasi Pengadaan" ke halaman
yang sama. Sidebar Kasubbag dan Admin jadi punya dua item bernama sama ke satu
halaman. Bedanya hanya penyaring status.

Narendra meminta: "hapus menu navigasi Persetujuan - Realisasi Pengadaan yang
dibuat untuk kasubbag umum ti, sama saja dan bisa diakses dengan yang ada di
Menu - Pengadaan - Realisasi Pengadaan."

## Keputusan

`MenuHelper::getApprovalItems()` dan grup "Persetujuan" di `getMenuGroups()`
dihapus. Rute, gate `setujui-pengadaan`, `ProcurementApprovalController`, dan
halaman realisasi tidak berubah.

Jalan pintas ke realisasi yang menunggu persetujuan masih ada di dua tempat:

- baris antrean dasbor "Realisasi menunggu persetujuan" (`setujui-pengadaan`),
  yang menaut ke `procurements.index?status=menunggu_persetujuan`;
- notifikasi keadaan `RealisasiMenungguPersetujuan`.

Dukungan kunci `query` pada item menu (`sidebar.blade.php`) dibiarkan walau
kini tidak ada item yang memakainya. Kodenya satu baris dan umum, dan tetap
benar bila kelak ada item menu yang membawa penyaring.

## Alternatif yang ditolak

- **Mempertahankan grup dengan nama lain** (mis. "Menunggu Persetujuan").
  Tetap dua tautan ke satu halaman, dan bukan yang diminta.
- **Membuka Realisasi Pengadaan dengan penyaring Menunggu Persetujuan sebagai
  bawaan bagi Kasubbag.** Tidak diminta, dan mengubah isi daftar yang sama
  untuk satu peran saja.

## Konsekuensi

- Kasubbag membuka realisasi yang menunggu persetujuannya lewat baris antrean
  dasbor, atau lewat Pengadaan › Realisasi Pengadaan lalu penyaring status.
  Satu klik lebih banyak dari sidebar.
- ADR 0030 menyebut grup ini dirancang untuk kelak menampung tautan
  persetujuan dari sub sistem permintaan barang. Grup itu kini tidak ada. Bila
  Alma butuh jalan pintas persetujuan, tempatnya di grup menu Permintaan miliknya.
