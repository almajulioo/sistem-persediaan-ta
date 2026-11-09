# ADR 0055 — Laporan Stok Opname dalam Excel, menggantikan PDF

## Status

Diterima — 9 November 2026

Menggantikan sebagian [ADR 0009](0009-laporan-pdf-dan-csv.md): Laporan Stok
Opname tidak lagi dibuat sebagai PDF. Laporan PDF lain (riwayat mutasi, kartu
persediaan, laporan pengadaan, BAST) dan ekspor CSV tidak berubah.

## Konteks

Narendra meminta: "ubah laporan stok opname yang dicetak dari pdf menjadi
excel".

Laporan opname sebelumnya PDF lanskap A4 lewat dompdf (`pdf/laporan-opname`):
kop lembaga, identitas dokumen, empat kartu rekap, tabel rincian seluruh barang
beserta alasan selisih, catatan bila stok bergerak setelah dicacah, dan ruang
tanda tangan. Tabelnya memuat **seluruh barang aktif** — ratusan hingga ribuan
baris — sehingga PDF-nya panjang dan tidak dapat disaring, diurutkan, atau
dijumlahkan.

`phpoffice/phpspreadsheet` sudah terpasang sejak
[ADR 0048](0048-ekspor-excel-usulan-pengadaan.md) untuk ekspor usulan pengadaan,
lengkap dengan pembantu kop, tabel, dan penulisan sel yang aman.

## Keputusan

**Rute.** `GET opname/{opname}/excel` (`opname.excel`, gate `akses-gudang`)
menggantikan `GET opname/{opname}/cetak` (`opname.pdf`). Templat
`pdf/laporan-opname.blade.php` dihapus. Tombol "Cetak Laporan" di rincian
menjadi **Unduh Excel**, dan "Cetak" di daftar menjadi **Excel**; keduanya
tetap hanya tampil untuk opname Selesai, sementara rutenya tetap melayani draf
(seperti rute PDF sebelumnya). Unduhan tidak dicatat di log aktivitas — membaca,
bukan mengubah.

**Isi mengikuti PDF, ditambah dua kolom.** Satu lembar "Stok Opname":

1. Kop: "Laporan Stok Opname Persediaan", nama lembaga, periode, waktu cetak dan
   pencetak.
2. Identitas: Tanggal Cacah, Petugas, Status, Diposting, Catatan.
3. Rekap: Jumlah Barang, Diisi Petugas, Mengikuti Stok Sistem (pada draf: Belum
   Diisi), Barang Berselisih, Selisih Lebih, Selisih Kurang.
4. Tabel: No, Kode Barang, Nama Barang, Satuan, Stok Sistem, Stok Fisik,
   Selisih, Penyesuaian, **Stok Saat Posting**, **Pencacahan**, Alasan Selisih,
   Keterangan. Judul kolom dibekukan, dapat disaring, dan diulang di setiap
   halaman cetak.
5. Catatan di bawah tabel bila ada baris yang stoknya bergerak setelah dicacah
   (kalimat yang sama dengan PDF), dan bila ada barang yang mengikuti stok
   sistem ([ADR 0056](0056-barang-tidak-diisi-mengikuti-stok-sistem.md)).

Dua kolom baru menggantikan catatan kecil di bawah nama barang pada PDF:
**Stok Saat Posting** (sel berwarna bila berbeda dari stok sistem, yaitu
`stokBerubahSejakDicacah()`) dan **Pencacahan** ("Diisi petugas", "Mengikuti
stok sistem", atau "Belum diisi" pada draf).

Angka ditulis sebagai angka sungguhan supaya dapat dijumlahkan; Selisih dan
Penyesuaian berformat bertanda (`+#,##0;-#,##0;0`). Teks — terutama keterangan
selisih yang diketik petugas — ditulis eksplisit sebagai string, sehingga teks
berawalan `=` tidak menjadi rumus (gotcha ADR 0048). Halaman disetel A4 lanskap,
muat selebar halaman.

**Pembantu bersama.** Kop, pasangan label–nilai, tabel, penulisan sel, dan
unduhan dipindah dari `LaporanUsulanPengadaan` ke trait baru
`App\Support\LembarExcel`, dipakai kedua laporan. `tabel()` kini mengembalikan
baris terakhirnya dan menerima format angka per kolom. Ekspor usulan pengadaan
tidak berubah (seluruh ujinya lulus tanpa disentuh). Pembangun laporan opname:
`App\Support\LaporanStokOpname`.

## Alternatif yang ditolak

**Menyediakan PDF dan Excel sekaligus.** Permintaannya "ubah ... menjadi",
bukan "tambahkan". Dua format berarti dua templat yang harus dijaga tetap
sama setiap kali isi laporan berubah — dan ADR 0056 baru saja mengubah isinya.

**CSV.** Tanpa kop, rekap, maupun format angka; ditolak
untuk ekspor usulan dengan alasan yang sama (ADR 0048).

**Tabel HTML dengan ekstensi `.xls`.** Excel membukanya dengan peringatan
format, dan angkanya tetap teks.

## Konsekuensi

- Tampilan cetak kini bergantung pada pengaturan halaman Excel (sudah disetel
  A4 lanskap, muat selebar halaman, judul kolom diulang), bukan pada CSS dompdf.
- Uji membaca berkas `.xlsx` yang diunduh (`StockOpnameExportTest`), termasuk
  memastikan keterangan berawalan `=` tersimpan sebagai teks.
- dompdf tetap dipakai laporan lain; tidak ada dependensi yang ditambah atau
  dibuang.
