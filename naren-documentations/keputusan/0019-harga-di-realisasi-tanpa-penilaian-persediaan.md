# ADR 0019 — Harga dicatat di realisasi pengadaan; penilaian persediaan di luar cakupan

## Status

Diterima — 6 September 2026

## Konteks

Sampai modul Pengadaan mulai dibangun, **tidak ada satu pun kolom uang di
seluruh basis data**. `items` tidak punya harga, `stock_mutations` tidak punya
nilai, dan `procurement_details` hanya menyimpan `amount`.

Modul Pengadaan memaksa pertanyaan itu terbuka, karena dua hal sekaligus:

1. **BAST mencantumkan nilai.** Berita acara serah terima barang lazimnya
   memuat harga satuan dan nilai total — dokumen tanpa nilai terasa janggal
   dan tidak dapat dipakai sebagaimana mestinya.
2. **"Berapa belanja persediaan periode ini?"** adalah pertanyaan yang wajar
   diajukan ke sistem persediaan, apalagi di lingkungan lembaga pemeriksa
   keuangan.

Godaannya adalah melangkah satu langkah lebih jauh: kalau harga sudah tercatat
di pengadaan, kenapa tidak sekalian menghitung **nilai persediaan** — berapa
rupiah barang yang tersimpan di gudang saat ini?

Langkah itu jauh lebih besar daripada kelihatannya. Nilai persediaan per barang
berubah setiap kali stok masuk dengan harga berbeda, sehingga membutuhkan
**metode costing** yang dipilih dan dipegang konsisten (FIFO, LIFO, atau
rata-rata bergerak). Konsekuensinya menyentuh tiga hal sekaligus:

- `StockLedger` harus ikut membawa nilai pada setiap mutasi, padahal ia adalah
  **kontrak lintas sub sistem** yang juga dipanggil Alma
  ([ADR 0004](0004-stok-hanya-lewat-stockledger.md)). Mengubah tanda tangannya
  berarti menyeret sub sistem Permintaan ke dalam keputusan akuntansi yang
  bukan urusannya.
- Mutasi keluar harus tahu harga perolehan lot mana yang dikeluarkan.
- Penyesuaian opname harus menilai selisih yang ditemukan.

## Keputusan

**Harga dicatat, penilaian persediaan tidak.**

Satu kolom baru: `procurement_details.unit_price`, harga satuan saat realisasi
pengadaan, bertipe `unsignedBigInteger`.

- **Integer, bukan `decimal`.** Rupiah tidak punya satuan pecahan yang dipakai
  dalam praktik, dan integer menutup seluruh kelas galat pembulatan floating
  point tanpa perlu memikirkan presisi.
- Nilai baris dihitung, tidak disimpan: `ProcurementDetail::subtotal()` =
  `amount × unit_price`. Nilai dokumen: `Procurement::nilaiTotal()`.
- Harga diambil dari yang **dipesan** (`amount`), bukan yang diterima —
  yang ditagihkan penyedia adalah pesanannya; kekurangan kiriman adalah
  perkara terpisah yang terlihat lewat `amount_received`
  ([ADR 0017](0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md)).

**Usulan pengadaan tidak menyimpan harga sama sekali.** Perkiraan harga saat
mengusulkan bukan angka yang bisa dipertanggungjawabkan, dan menyimpannya
mengundang orang membandingkannya dengan realisasi seolah-olah selisihnya
bermakna.

**Yang secara tegas berada di luar cakupan sistem ini:** nilai persediaan per
barang, nilai persediaan total gudang, harga pokok barang keluar, dan penilaian
selisih opname dalam rupiah.

## Alternatif yang ditolak

**Tidak mencatat harga sama sekali.** Paling sederhana dan paling konsisten
dengan keadaan sekarang — modul murni soal kuantitas dan alur dokumen. Ditolak
karena BAST menjadi dokumen yang tidak lengkap, dan pertanyaan "berapa belanja
periode ini" tidak terjawab padahal datanya sebenarnya sudah lewat di depan
sistem.

**Mencatat harga sekaligus menghitung nilai persediaan dengan rata-rata
bergerak.** Metode ini yang paling lazim dipakai instansi pemerintah dan
secara teknis bukan hal mustahil. Ditolak untuk versi ini karena ongkosnya
bukan pada rumusnya, melainkan pada penyebarannya: `StockLedger` harus berubah,
dan itu kontrak yang dipakai bersama pengembang lain. Menambah beban ke sana
demi fitur yang tidak diminta narasumber lapangan adalah pertukaran yang buruk.
Bila kelak dibutuhkan, kolom `unit_price` yang ditetapkan sekarang justru
menjadi bahan bakunya.

**Menyimpan perkiraan harga pada usulan pengadaan
(`procurement_request_details.estimated_price`).** Berguna untuk menampilkan
perkiraan anggaran sebelum usulan disetujui. Ditolak karena perkiraan yang
disimpan berdampingan dengan harga sebenarnya akan dibaca sebagai target, lalu
selisihnya dibaca sebagai penyimpangan — padahal keduanya tidak pernah
dimaksudkan setara. Bila anggaran perlu dikendalikan, itu keputusan tersendiri
dengan sumber angka yang jelas, bukan efek samping dari kolom perkiraan.

**Menyimpan `subtotal` sebagai kolom.** Menghemat perhitungan saat menampilkan.
Ditolak: nilai turunan yang disimpan bisa menyimpang dari sumbernya, dan
jumlah baris pada satu pengadaan terlalu sedikit untuk membuat perhitungannya
terasa.

## Konsekuensi

- Laporan pengadaan dapat menampilkan nilai per dokumen dan total per periode.
  Laporan **persediaan** tetap murni kuantitas.
- BAST memuat kolom harga satuan dan jumlah, sehingga dapat langsung
  ditandatangani tanpa dilengkapi tulisan tangan.
- Pertanyaan sidang "mengapa tidak ada nilai persediaan?" punya jawaban yang
  tercatat dan beralasan, bukan sekadar belum sempat.
- `StockLedger` tidak berubah sama sekali, sehingga sub sistem Permintaan tidak
  ikut terpengaruh.
- Bila penilaian persediaan kelak benar-benar dibutuhkan, ia menjadi ADR baru
  yang menggantikan bagian "di luar cakupan" pada ADR ini — bukan suntingan
  atas ADR ini.
