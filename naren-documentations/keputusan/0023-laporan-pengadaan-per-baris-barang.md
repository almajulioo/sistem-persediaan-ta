# ADR 0023 — Laporan pengadaan berbutir barang, dan BAST mencetak jumlah diterima

## Status

Diterima — 6 September 2026

## Konteks

[ADR 0009](0009-laporan-pdf-dan-csv.md) sudah menetapkan teknologinya: PDF
lewat dompdf, CSV lewat `fputcsv` bawaan, dan isi laporan selalu mengikuti
penyaring yang sedang aktif di layar. Yang belum ditetapkannya, karena saat itu
belum ada modul yang membutuhkannya, adalah dua hal yang baru terlihat ketika
Tahap 4 ditulis:

1. **Satu baris laporan pengadaan itu apa** — satu dokumen realisasi, atau satu
   barang pada satu realisasi?
2. **Angka mana yang dicetak BAST** — yang dipesan, atau yang datang?

Pertanyaan kedua terlihat sudah dijawab
[ADR 0017](0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md), tetapi ADR itu
berbicara tentang stok. BAST adalah dokumen hukum yang ditandatangani dua pihak,
dan pertanyaannya berdiri sendiri.

## Keputusan

### Satu baris laporan = satu barang pada satu realisasi

Kolom dokumen (tanggal, nomor realisasi, penyedia, status, nomor BAST) diulang
pada tiap baris barang. Laporan pengadaan karena itu terbaca sebagai **daftar
barang yang diadakan**, bukan daftar dokumen.

### Rekap membawa dua nilai, tidak pernah satu

`nilai` (dari `amount × unit_price`) dan `nilai_diterima` (dari
`amount_received × unit_price`) selalu ditampilkan berdampingan — di kartu
rekap layar, di kepala laporan PDF, dan sebagai dua kolom terpisah di CSV.
Selisih keduanya adalah kekurangan kiriman penyedia.

Rekap di layar juga **mengikuti penyaring**, bukan seluruh basis data, supaya
angka di kartu selalu sama dengan angka di laporan yang dicetak dari halaman
yang sama.

### Rentang tanggal memakai `procurement_date`

Penyaring tanggal — di layar maupun di laporan — bekerja pada tanggal
realisasi, dan laporan diurutkan menaik menurut tanggal itu. Ini **sengaja
berbeda** dari buku mutasi, yang [ADR 0005](0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)
haruskan diurutkan menurut waktu pencatatan.

### BAST mencetak `amount_received`, dan hanya terbit setelah penerimaan

Seluruh angka pada BAST adalah yang benar-benar diterima. Barang yang datang
kurang menghasilkan satu paragraf catatan yang menyebut jenis dan jumlah
kekurangannya secara eksplisit, bukan disembunyikan.

Rute BAST menolak realisasi yang belum punya `ProcurementReceipt` dengan
redirect berpesan.

## Alternatif yang ditolak

**Satu baris per realisasi, dengan kolom "3 barang".** Laporannya jauh lebih
pendek dan lebih rapi dibaca sebagai daftar dokumen. Ditolak karena pertanyaan
yang paling sering diajukan ke laporan pengadaan justru "sepanjang tahun ini
kita mengadakan barang apa saja, berapa banyak, berapa harganya" — dan bentuk
itu tidak dapat menjawabnya sama sekali. Menyediakannya berarti harus membuat
laporan kedua berbutir barang, dan dua laporan yang menghitung hal yang sama
dengan cara berbeda adalah dua sumber kebenaran.

**Satu kolom "nilai pengadaan" saja.** Lebih ringkas dan cukup untuk
rekapitulasi anggaran. Ditolak karena menghapus persis angka yang menjadi
alasan ADR 0017 ada: kekurangan kiriman. Nilai tunggal memaksa memilih antara
melaporkan uang yang dipesan (yang melebih-lebihkan barang yang ada) atau uang
yang diterima (yang menyembunyikan bahwa penyedia masih berutang kiriman).
Keduanya salah; keduanya perlu terlihat.

**Mengurutkan laporan menurut `created_at` seperti buku mutasi.** Terlihat
konsisten. Ditolak karena alasan pengurutan di ADR 0005 adalah `stock_after` —
saldo berjalan yang hanya koheren bila dibaca menurut urutan pencatatan.
Laporan pengadaan tidak punya saldo berjalan apa pun. Yang dilaporkan adalah
kapan pengadaannya terjadi, bukan kapan barisnya diketik.

**BAST dapat dicetak begitu realisasi dibuat, untuk ditandatangani lebih dulu
lalu jumlahnya diisi tangan saat barang datang.** Menghemat satu langkah bagi
petugas. Ditolak karena itu persis urutan "kertas mendahului sistem" yang
ditolak [ADR 0018](0018-berkas-unggahan-di-disk-privat.md), dan lebih buruk:
angka yang tercetak adalah jumlah **pesanan**, sehingga dokumen bertanda tangan
menyatakan penyerahan barang yang belum tentu diserahkan. Sistem tidak boleh
mencetak pernyataan yang belum tentu benar.

**Menolak cetak BAST untuk kiriman yang kurang, sampai penyedia melengkapi.**
Menjaga agar BAST selalu menyatakan pesanan yang utuh. Ditolak karena kiriman
kurang justru keadaan yang **paling** membutuhkan dokumen bertanda tangan:
tanpa BAST, tidak ada bukti apa pun tentang apa yang sudah diserahkan dan
berapa sisa kewajiban penyedia. Kekurangannya dinyatakan di dokumen, bukan
dijadikan alasan menahan dokumennya.

## Konsekuensi

- Baris laporan bertambah lebih cepat daripada jumlah dokumen, karena itu batas
  ekspor pengadaan (2.000 realisasi) lebih ketat daripada batas laporan mutasi
  (5.000 baris).
- `Procurement::nilaiDiterima()` melengkapi `nilaiTotal()`; keduanya dihitung
  dari rincian, bukan disimpan sebagai kolom, supaya tidak ada dua sumber
  kebenaran (pola yang sama dengan `diterimaSebagian()`).
- Rekap yang mengikuti penyaring dihitung lewat subkueri ke
  `procurement_details`, bukan dengan menarik daftar id lebih dulu: jumlah
  realisasi yang tersaring tidak dibatasi apa pun di layar daftar.
- `Procurement::scopeFilter()` kini menerima empat penyaring (status, penyedia,
  tanggal awal, tanggal akhir) dan dipakai bersama oleh layar, PDF, CSV, dan
  penjaga batas ekspor — satu definisi, empat pemakai.
