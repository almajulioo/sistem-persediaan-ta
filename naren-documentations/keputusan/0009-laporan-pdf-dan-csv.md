# ADR 0009 — Laporan PDF lewat dompdf dan CSV tanpa paket tambahan

## Status

Diterima — 3 September 2026

## Konteks

Rancangan antarmuka riwayat mutasi memuat tombol Cetak dengan rentang tanggal.
Kebutuhannya ada dua dan berbeda sifat: laporan resmi yang dapat diarsipkan dan
ditandatangani, serta data mentah yang dapat diolah lanjut di Excel.

## Keputusan

**PDF** memakai `barryvdh/laravel-dompdf`, dengan templat Blade ber-CSS biasa
karena dompdf tidak mengenal Tailwind. Laporan berkop teks lembaga, memuat
periode dan penyaring yang dipakai, tabel rekap, tabel data, ruang tanda tangan,
serta footer berisi waktu cetak dan nama pencetak.

**CSV** memakai `response()->streamDownload()` dan `fputcsv()` bawaan PHP, dengan
**BOM UTF-8** di awal supaya Excel membaca karakter Indonesia dengan benar, dan
pemisah titik koma agar cocok dengan Excel lokal.

**Kueri penyaring ditulis satu kali** sebagai method privat, lalu dipakai bersama
oleh layar, PDF, dan CSV — sehingga isi laporan tidak mungkin berbeda dari yang
dilihat pengguna.

Infrastruktur yang sama dipakai ulang untuk **Kartu Persediaan** per barang.

## Alternatif yang ditolak

**`maatwebsite/excel` untuk berkas .xlsx asli.** Hasilnya lebih rapi untuk diolah
lanjut, tetapi menambah dua paket dan `phpspreadsheet` cukup berat. CSV sudah
terbuka langsung di Excel dan mencukupi kebutuhan.

**Hanya PDF.** Data tidak dapat diolah ulang di Excel.

**Hanya CSV.** Tidak ada bentuk laporan resmi untuk diarsipkan atau
ditandatangani.

## Konsekuensi

- Menambah satu dependensi. Versi dipasang `~3.1` alih-alih `^3.1` karena tanda
  sisipan dimakan oleh `composer.bat` di Windows; kedua notasi setara.
- Berkas PDF berukuran sekitar 1 MB karena dompdf menyertakan font DejaVu Sans.
  Dipilih demi kepastian penulisan karakter Indonesia; dapat ditekan dengan font
  inti bila kelak dianggap mengganggu.
- Ekspor dibatasi 5.000 baris agar satu permintaan tidak menghabiskan memori;
  bila lebih, pengguna diminta mempersempit rentang tanggal.
- Templat PDF tidak dapat memakai kelas Tailwind, sehingga gaya laporan
  dipelihara terpisah di `resources/views/pdf/_style.blade.php`.
