# ADR 0010 — Alur stok opname: draf, posting wajib lengkap, dan penyesuaian hanya menerapkan selisih hasil cacah

## Status

Diterima — 4 September 2026

## Konteks

Activity diagram awal menggambarkan stok opname sebagai satu jalur lurus:
petugas mencacah, sistem membandingkan dengan stok tercatat, lalu dokumen
laporan dibuat. Tiga hal tidak terjawab oleh gambar itu.

**Pencacahan tidak selesai dalam satu duduk.** Lima belas barang masih dapat
diselesaikan sekali jalan, tetapi gudang nyata memuat ratusan. Tanpa keadaan
draf, petugas yang berhenti di tengah kehilangan seluruh isian.

**Dokumen laporan digambar bercabang paralel dengan pengecekan selisih**,
sehingga dokumen terbentuk *sebelum* alasan penyesuaian dimasukkan. Laporan
seperti itu memuat selisih tanpa penjelasan — justru bagian yang paling
ditanyakan pemeriksa.

**Stok dapat bergerak antara pencacahan dan posting.** Gudang tidak berhenti
melayani permintaan hanya karena opname sedang berjalan. Diagram tidak
menyinggung keadaan ini sama sekali, padahal inilah pertanyaan tersulit dalam
modul ini: angka mana yang benar ketika stok sudah berubah sejak barang dihitung?

## Keputusan

### Alur

Opname berjalan dalam dua keadaan: **Draf** dan **Selesai**.

Draf dibuat dengan menyalin (*snapshot*) seluruh barang aktif beserta stok
tercatatnya ke `opname_details.stock_stored`. Barang yang didaftarkan setelah
draf dibuat tidak ikut — memang seharusnya begitu, karena opname mencakup barang
yang ada pada saat pencacahan dimulai.

Hasil cacah boleh disimpan sebagian berkali-kali. **Posting menuntut kelengkapan**
dan ditolak bila salah satu dari tiga syarat tidak dipenuhi:

1. Dokumen masih berstatus draf;
2. Seluruh barang sudah dicacah — tidak ada `stock_real` yang kosong;
3. Setiap baris berselisih memiliki alasan **dan** keterangan.

Setelah diposting dokumen bersifat final: tidak dapat diubah, dihapus, maupun
diposting ulang — karena penyesuaiannya sudah tercatat di buku mutasi yang
immutable ([ADR 0005](0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)).
Dokumen laporan dibuat **setelah** posting dan dapat dicetak ulang kapan saja
dari data yang tersimpan.

### Penyesuaian: yang dikoreksi adalah selisih hasil cacah

Penyesuaian yang diterapkan adalah **`difference`**, yaitu selisih yang
teramati saat mencacah:

```
difference = stock_real − stock_stored
```

Bukan jarak antara hasil cacah dan stok pada detik posting. Alasannya: yang
ditemukan opname adalah **kesalahan pencatatan** — barang yang jumlahnya di buku
tidak sama dengan yang ada di rak pada saat dihitung. Transaksi yang terjadi
*setelah* pencacahan bukan kesalahan; ia kejadian sah yang sudah tercatat sendiri
di buku mutasi.

Konsekuensinya stok akhir tidak selalu sama dengan `stock_real`:

```
stok akhir = stock_at_posting + difference
```

Bila tidak ada transaksi di antara pencacahan dan posting, `stock_at_posting`
sama dengan `stock_stored` dan kedua rumus menghasilkan angka yang sama. Bila
ada, baris itu ditandai di layar dan di PDF beserta penjelasannya.

Baris tanpa selisih tidak menghasilkan mutasi sama sekali — `StockLedger`
menolak jumlah nol untuk jenis dua arah seperti `Penyesuaian`. Baris itu tetap
mencatat `adjustment_amount = 0` sebagai bukti bahwa ia sudah diperiksa.

Seluruh penerapan berjalan dalam satu `DB::transaction`. Baris barang dikunci
`lockForUpdate()` di `OpnamePoster` **sebelum** selisih dibaca; `StockLedger`
mengunci baris yang sama di dalam transaksi yang sama — aman, karena kuncinya
sudah dipegang. Inilah yang menutup celah balapan antara membaca stok dan
menerapkan mutasi.

## Alternatif yang ditolak

### Memaksa stok akhir sama dengan hasil cacah

**Alternatif ini sempat disetujui dan diimplementasikan**, dengan rumus
`adjustment_amount = stock_real − stock_at_posting`. Argumennya terdengar kuat:
hasil cacah fisik adalah kebenaran, jadi stok sistem harus dibuat sama dengannya.

Verifikasi manual lewat HTTP membuktikan rumus itu **salah**. Skenarionya:

| Langkah | Stok |
|---|---|
| Stok tercatat saat draf dibuat | 132 |
| Hasil cacah — cocok, tidak ada kesalahan pencatatan | 132 |
| Setelah pencacahan, 20 unit dicatat rusak lewat mutasi manual | 112 |
| Posting dengan rumus lama: `132 − 112 = +20` | **132** |

Posting **membatalkan kerusakan yang sah tercatat**. Buku mutasi memuat baris
Barang Rusak −20 dan baris Penyesuaian +20 yang saling meniadakan, sehingga 20
unit rusak itu hidup kembali di sistem tanpa ada yang menyatakannya kembali
layak pakai.

Kesalahannya mendasar: rumus itu memperlakukan seluruh jarak antara hasil cacah
dan stok terkini sebagai kesalahan pencatatan, padahal sebagian di antaranya
justru transaksi yang benar. Opname tidak berwenang membatalkan transaksi.

### Membekukan seluruh transaksi selama opname berjalan

Menghilangkan persoalannya sejak awal, tetapi menghentikan pelayanan gudang
selama pencacahan berlangsung. Untuk gudang persediaan yang melayani permintaan
harian, biayanya lebih besar daripada masalah yang diselesaikan.

### Membolehkan posting sebagian

Petugas dapat memposting barang yang sudah dicacah dan melanjutkan sisanya
kemudian. Ditolak karena laporan stok opname adalah dokumen
pertanggungjawaban per periode: dokumen yang hanya memuat sebagian barang tidak
dapat dinyatakan sebagai hasil opname periode itu.

### Opname selesai boleh dibatalkan atau disunting

Ditolak karena bertentangan dengan
[ADR 0005](0005-buku-mutasi-immutable-dan-urutan-pencatatan.md). Mutasi
penyesuaian sudah menjadi bagian buku besar; membatalkannya berarti menyunting
riwayat. Bila hasil opname keliru, perbaikannya adalah **opname baru** atau
mutasi Koreksi Pencatatan yang meninggalkan jejaknya sendiri.

## Konsekuensi

- **Stok akhir dapat berbeda dari hasil cacah** bila ada transaksi di tengah
  opname. Ini benar secara akuntansi, tetapi berpotensi mengejutkan petugas.
  Karena itu baris tersebut ditandai di layar hasil dan di PDF, disertai kalimat
  penjelas.
- `opname_details` menyimpan lima angka, bukan tiga. `stock_at_posting` dan
  `adjustment_amount` menjadi bukti apa yang benar-benar diterapkan, terpisah
  dari apa yang teramati saat mencacah.
- Penyesuaian kurang dapat membuat stok minus bila banyak barang keluar setelah
  pencacahan. `StockLedger` menolaknya dan **seluruh posting dibatalkan**;
  controller menangkap `InsufficientStockException` dan meminta petugas memeriksa
  ulang hasil cacah.
- Menyelesaikan opname menjadi lebih menuntut: seluruh barang wajib dicacah dan
  setiap selisih wajib dijelaskan. Ini disengaja — dokumen setengah jadi tidak
  dapat dipertanggungjawabkan.
- Draf dapat dihapus, opname selesai tidak. Draf belum menyentuh stok sama
  sekali.
