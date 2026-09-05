# ADR 0017 — Jumlah diterima dicatat terpisah dari jumlah dipesan

## Status

Diterima — 6 September 2026

## Konteks

Sampai keputusan ini diambil, `procurement_details` hanya punya satu kolom
jumlah: `amount`. Artinya begitu penerimaan dikonfirmasi, stok bertambah
**sebanyak yang dipesan**.

Di lapangan, penyedia sering mengirim kurang dari pesanan: kehabisan barang,
salah hitung, atau sebagian menyusul. Sistem yang tidak menyediakan tempat
untuk mencatat "pesan 100, datang 80" meninggalkan Pengelola Gudang dengan
tiga pilihan yang semuanya buruk:

1. **Tidak mengonfirmasi sama sekali.** Stok sistem tidak pernah bertambah
   meski barangnya sudah ada di rak. Lama-lama sistemnya ditinggalkan.
2. **Mengonfirmasi angka yang salah.** Stok sistem menyimpang dari fisik
   gudang. Selisihnya baru muncul saat stok opname berikutnya, sebagai selisih
   yang harus dikarang alasannya — padahal penyebabnya diketahui persis sejak
   awal.
3. **Mengonfirmasi penuh lalu menambal dengan mutasi koreksi manual.** Secara
   angka benar, tetapi jejaknya pecah menjadi dua dokumen dan terbaca seperti
   kesalahan pencatatan, bukan seperti kekurangan kiriman penyedia.

Ketiganya bermuara pada hal yang sama: **sistem memaksa petugas berbohong,
atau memaksanya bekerja di luar sistem.** Di situlah sebuah modul berubah
menjadi formalitas.

## Keputusan

**`procurement_details.amount_received`** — kolom `unsignedInteger` nullable,
terisi saat penerimaan dikonfirmasi.

Dua kolom jumlah dengan arti yang tegas berbeda:

| Kolom | Arti | Terisi kapan |
|---|---|---|
| `amount` | Jumlah yang **dipesan** kepada penyedia | Saat realisasi dibuat |
| `amount_received` | Jumlah yang **benar-benar datang** | Saat penerimaan dikonfirmasi |

Konsekuensi turunannya:

- **Stok bertambah sebanyak `amount_received`, bukan `amount`.** Baris dengan
  `amount_received` bernilai nol tidak menghasilkan mutasi sama sekali —
  `StockLedger` memang menolak jumlah nol untuk jenis satu arah.
- **BAST mencetak apa yang benar-benar diterima**, sehingga dokumen yang
  ditandatangani menyatakan kenyataan dan layak ditandatangani.
- **Nilai rupiah tetap dihitung dari `amount`** — yang ditagihkan penyedia
  adalah pesanannya. Lihat
  [ADR 0019](0019-harga-di-realisasi-tanpa-penilaian-persediaan.md).

**Kekurangan kiriman tidak menjadi status tersendiri.** Pertanyaan "apakah ada
yang datang kurang?" dijawab dari rincian lewat `Procurement::diterimaSebagian()`,
bukan dari kolom status. Menyimpannya sebagai status berarti dua sumber
kebenaran yang bisa saling bertentangan.

## Alternatif yang ditolak

**Membiarkan stok bertambah sebanyak yang dipesan, lalu mengoreksi kekurangan
lewat mutasi manual `KoreksiPencatatan`.** Tidak butuh kolom baru sama sekali,
dan secara angka hasil akhirnya sama. Ditolak karena namanya berbohong:
kekurangan kiriman penyedia **bukan** kesalahan pencatatan. Buku mutasi akan
memuat baris koreksi yang tidak pernah mengoreksi apa pun, dan kartu persediaan
menjadi lebih sulit dibaca justru pada bagian yang paling perlu jelas.

**Menyimpan `amount_received` pada tabel penerimaan
(`procurement_receipt_details`) alih-alih pada rincian realisasi.** Secara
normalisasi lebih tepat: jumlah diterima adalah atribut peristiwa penerimaan,
bukan atribut pesanan. Ditolak untuk versi ini karena penerimaan sudah
ditetapkan 1:1 terhadap realisasi (ADR 0015), sehingga tabel tambahan hanya
menduplikasi baris tanpa menambah kemampuan apa pun. Bila kelak penerimaan
bertahap dibutuhkan, tabel itulah yang dibuat — dan `amount_received` pada
rincian realisasi berubah peran menjadi akumulasi.

**Mewajibkan `amount_received` sama dengan `amount`, dengan alasan pesanan
yang tidak lengkap seharusnya tidak diterima.** Ditolak: itu memindahkan
masalah dari sistem ke lapangan, dan yang terjadi bukan penyedia jadi lebih
tertib, melainkan barang tetap masuk gudang tanpa tercatat.

## Konsekuensi

- Formulir konfirmasi penerimaan menampilkan jumlah dipesan sebagai acuan dan
  meminta jumlah diterima per barang — nilai awalnya sama dengan yang dipesan,
  supaya kasus lazim tetap satu klik.
- Selisih kiriman terlihat sebagai fakta di halaman pengadaan dan di BAST,
  bukan tersembunyi sebagai catatan bebas.
- Stok opname berikutnya tidak lagi menemukan selisih misterius yang berasal
  dari kekurangan kiriman, karena selisihnya sudah tercatat pada tempat yang
  benar sejak awal.
- Kolomnya nullable, sehingga "sudah dipesan tetapi belum diterima" dapat
  dibedakan dari "diterima nol" — keduanya keadaan yang berbeda dan keduanya
  mungkin terjadi.
