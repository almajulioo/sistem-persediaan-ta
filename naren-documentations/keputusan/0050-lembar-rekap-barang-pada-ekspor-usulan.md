# ADR 0050 — Lembar Rekap Barang pada ekspor daftar usulan

## Status

Diterima — 8 November 2026

Sebagian menggantikan [ADR 0048](0048-ekspor-excel-usulan-pengadaan.md):
lembar "Rekap Barang", yang di sana tercatat sebagai alternatif yang
ditolak, kini menjadi lembar kedua ekspor daftar usulan.

## Konteks

Konsep ekspor yang dibahas sebelum ADR 0048 menjanjikan dua lembar untuk
ekspor per rentang waktu: **Ringkasan** (satu baris per usulan) dan
**Rekap Barang** (satu baris per barang, digabung dari semua usulan).
Pertanyaan apakah Rekap Barang dibutuhkan diajukan ke Narendra, tetapi
tidak terjawab.

Jawaban Narendra atas pertanyaan lain ("minimal saja", "skema tabel yang
sekarang") **ditafsirkan oleh Claude** sebagai tidak perlu Rekap Barang.
ADR 0048 lalu mencatatnya sebagai alternatif yang ditolak "karena tidak
diminta". Ringkasan pekerjaannya tidak menyebutkan hal itu dengan jelas.

Narendra menemukan berkasnya hanya punya satu lembar, padahal sesuai
rencana seharusnya dua. Penolakan di ADR 0048 adalah salah tafsir, bukan
keputusan Narendra.

## Keputusan

Ekspor daftar usulan (`LaporanUsulanPengadaan::daftar()`) memuat dua
lembar. Berkas terbuka di lembar pertama.

**Ringkasan.** Isinya tetap sama dengan ADR 0048. Hanya nama lembarnya
berubah dari "Usulan Pengadaan" menjadi "Ringkasan", sesuai rencana.

**Rekap Barang.** Kopnya sama: judul "Rekap Barang Usulan Pengadaan",
periode, status bila disaring, dan pencetak. Barisnya dari rincian semua
usulan yang lolos penyaring yang sama, satu baris per barang:

| Kolom | Isi |
|---|---|
| No | |
| Kode Barang, Nama Barang, Satuan | dari master barang |
| Jumlah Usulan | banyak usulan berbeda yang memuat barang itu |
| Diminta | jumlah `amount_request` |
| Disetujui | jumlah `amount_granted`; yang belum diputuskan dihitung 0 |
| Belum Diputuskan | jumlah `amount_request` yang `amount_granted`-nya masih null |

Urutannya Jumlah Usulan menurun, lalu kode barang. Lembar ini menjawab
"barang apa yang paling sering kita usulkan". Pengguna tetap bisa
mengurutkan ulang lewat penyaring otomatis.

**Belum Diputuskan** adalah tambahan dari rencana awal. Tanpa kolom ini,
barang dari usulan yang masih `Diajukan` membuat Disetujui tampak rendah,
padahal keputusannya belum ada. Kolom "tidak disetujui" sengaja tidak
dibuat: persetujuan sebagian (ADR 0020) membuat selisih Diminta dan
Disetujui bukan berarti ditolak.

Usulan `Ditolak` ikut dihitung di Diminta, dengan Disetujui 0. Ini konsisten
dengan lembar Ringkasan yang juga menampilkannya. Untuk melihat rekap
tanpa usulan ditolak, pakai penyaring status.

Ekspor satu usulan tidak berubah dan tetap satu lembar. Rincian barangnya
sudah ada di lembar itu.

## Alternatif yang ditolak

**Lembar rincian barang per usulan** (satu baris per barang per usulan,
seperti laporan pengadaan ADR 0023). Bukan yang direncanakan, dan rincian
per usulan sudah tersedia lewat ekspor satu usulan.

**Mengikuti rencana awal persis, tanpa Belum Diputuskan.** Lihat
Keputusan.

## Konsekuensi

- Pengendali kini memuat `details.item` untuk ekspor daftar, bukan hanya
  `withCount('details')`. Jumlah Barang di Ringkasan dihitung dari koleksi
  yang sama.
- Pelajaran proses: pertanyaan yang tidak terjawab jangan diputuskan
  diam-diam dari tafsiran jawaban lain. Kalau terpaksa diputuskan, sebut
  dengan jelas di ringkasan pekerjaan, bukan hanya di ADR.
