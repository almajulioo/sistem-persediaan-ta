# ADR 0025 — Realisasi dapat diubah selama belum dikonfirmasi diterima

## Status

Diterima — 7 September 2026

## Konteks

[ADR 0024](0024-pembatalan-realisasi-pengadaan.md) memberi realisasi jalan keluar
lewat pembatalan, dan menyertakan `AlasanPembatalan::KesalahanPencatatan` sebagai
jalan keluar bagi realisasi yang datanya salah ketik — salah penyedia, salah
harga, atau salah jumlah. Sehari kemudian pemakaian nyata menunjukkan jalan
keluar itu **jauh terlalu mahal**.

Pembatalan bersifat terminal bagi usulannya. Jadi satu angka nol yang kelebihan
pada kolom harga menghasilkan rangkaian ini:

1. Realisasinya dibatalkan.
2. Usulan yang sudah disetujui ikut mati dan tidak dapat direalisasikan ulang.
3. Pengelola Gudang harus mengajukan usulan baru dari nol, lalu Pejabat Pengadaan
   menyetujuinya lagi — dua orang, dua langkah, untuk memperbaiki satu digit.
4. Satu baris realisasi berstatus `Dibatalkan` tertinggal permanen di riwayat
   penyedia, padahal tidak pernah ada apa pun yang terjadi dengan penyedia itu.

Akibat keempatlah yang membuat masalahnya terlihat: laporan pengguna berbunyi
"kalau saya membatalkan lima kali karena salah ketik, penyedia tertulis sudah
lima kali pengadaan padahal semuanya batal."

Padahal selama status masih `MenungguPenerimaan`, **belum ada apa pun yang
terjadi secara fisik**: barang belum diterima, stok belum bertambah, tidak ada
satu pun baris `stock_mutations`, dan BAST belum terbit. Dokumennya masih berada
di tangan orang yang membuatnya. Yang keliru bukan keputusannya, hanya
pengetikannya.

Proyek ini sudah punya preseden untuk keadaan seperti itu:
[ADR 0010](0010-alur-stok-opname-dan-penyesuaian-selisih.md) membiarkan draf
opname diubah bebas sampai diposting, dan baru menguncinya setelah penyesuaian
masuk ke buku mutasi yang immutable.

## Keputusan

### Realisasi dapat diubah selama `MenungguPenerimaan`

Dua rute baru di bawah gate `kelola-pengadaan` — dokumen milik Pejabat
Pengadaan, jadi dialah yang memperbaikinya, sejalan dengan logika "siapa yang
memutuskan" [ADR 0016](0016-alur-pengadaan-tiga-dokumen.md):

```
GET  pengadaan/{procurement}/edit    procurements.edit
PUT  pengadaan/{procurement}         procurements.update
```

Batasnya sama persis dengan pembatalan: **hanya dari `MenungguPenerimaan`**.
Begitu barang diterima, stoknya sudah bertambah lewat mutasi yang immutable
([ADR 0005](0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)) dan dokumennya
mengunci. Konsekuensi baiknya sama pula: **tidak ada satu pun mutasi stok yang
perlu disesuaikan**, sehingga `StockLedger` dan `ProcurementReceiptPoster` tidak
tersentuh sama sekali oleh fitur ini.

### Yang boleh diubah, dan yang tidak

| Kolom | Boleh diubah? |
|---|---|
| `supplier_id` | Ya |
| `procurement_date` | Ya |
| `procurement_details.amount` | Ya, tetap dibatasi `≤ amount_granted` |
| `procurement_details.unit_price` | Ya |
| `procurement_request_id` | **Tidak** |
| Menambah / menghapus baris barang | **Tidak** |

`procurement_request_id` terkunci karena memindahkan realisasi ke usulan lain
menabrak indeks unik ADR 0016 sekaligus menghapus jejak asal-usulnya. Susunan
barangnya terkunci karena memang ditetapkan usulan yang disetujui — dan justru
karena itu aturan [ADR 0021](0021-realisasi-dan-penerimaan-pengadaan.md) ("wajib
mencakup seluruh rincian yang disetujui") terpenuhi dengan sendirinya.

Batas `amount ≤ amount_granted` tetap berlaku: mengubah tidak boleh menjadi celah
memesan melebihi yang disetujui.

### Baris diperbarui di tempat

Kunci barisnya `procurement_detail_id` — baris yang sudah ada — bukan
`procurement_request_detail_id` seperti saat pembuatan. Barisnya diperbarui,
bukan dihapus lalu dibuat ulang, sehingga `procurement_detail_id` tetap stabil
dan formulir konfirmasi penerimaan tetap menunjuk baris yang sama.

Kiriman wajib mencakup **seluruh** baris. Baris yang hilang akan tertinggal
memakai nilai lamanya tanpa disadari siapa pun — alasan yang sama yang membuat
konfirmasi penerimaan menuntut kelengkapan.

### `KesalahanPencatatan` tetap ada, tetapi menyempit

Case-nya tidak dihapus: masih ada kekeliruan yang tidak dapat diperbaiki dengan
mengubah, yaitu realisasi yang dibuat dari **usulan yang sama sekali salah**,
karena `procurement_request_id` terkunci. Yang berubah `description()`-nya —
mengarahkan agar salah ketik biasa diperbaiki lewat Ubah Realisasi lebih dulu.

Efek keseluruhannya: `AlasanPembatalan` kembali menjadi daftar **kegagalan nyata
di lapangan**, bukan campuran kegagalan lapangan dan kekeliruan administrasi.

### Tombol Ubah didahulukan atas Batalkan

Pada halaman detail realisasi, **Ubah Realisasi** diletakkan di atas **Batalkan
Realisasi**. Urutan tombol adalah anjuran: yang pertama terlihat adalah yang
seharusnya lebih sering dipakai.

## Alternatif yang ditolak

**Membiarkan `KesalahanPencatatan` sebagai satu-satunya jalur.** Tidak ada kode
baru sama sekali. Ditolak karena menghukum kekeliruan administratif dengan
mematikan dokumen yang sah: satu digit yang salah membatalkan persetujuan yang
sudah diberikan, dan memaksa dua orang mengulang pekerjaan yang sudah benar.
Sistem yang ongkos koreksinya setinggi itu akan membuat orang enggan
mengoreksi — dan data yang salah tetapi dibiarkan lebih buruk daripada data yang
diperbaiki.

**Menghapus lalu membuat ulang baris rinciannya saat menyimpan.** Lebih ringkas,
dan dapat memakai ulang persis muatan serta validasi `StoreProcurementRequest`.
Ditolak karena membuang `procurement_detail_id` yang stabil tanpa perlu —
padahal susunan barangnya memang tidak dapat berubah, sehingga yang sebenarnya
dibutuhkan hanya memperbarui dua kolom angka.

**Mengizinkan `procurement_request_id` ikut diubah.** Menjadikan seluruh isi
dokumen dapat diperbaiki. Ditolak: itu memindahkan realisasi ke usulan lain,
menabrak indeks unik ADR 0016, dan membuat "usulan mana yang menghasilkan
realisasi ini" tidak lagi punya jawaban tetap. Realisasi yang dibuat dari usulan
yang keliru memang harus dibatalkan — itulah satu-satunya kasus
`KesalahanPencatatan` yang tersisa.

**Mengizinkan pengubahan setelah barang diterima.** Menjadikan fiturnya berlaku
di semua keadaan. Ditolak karena stok sudah bertambah lewat mutasi yang
immutable, sehingga mengubah `amount` di belakangnya membuat dokumen dan buku
mutasi bercerita berbeda. Koreksi setelah penerimaan sudah punya jalurnya
sendiri: mutasi lawan bertipe `KoreksiPencatatan`
([ADR 0006](0006-mutationtype-merangkap-alasan-manual.md)).

**Menyediakan pengubahan hanya untuk harga, bukan penyedia.** Menjaga agar
"kepada siapa dipesan" tidak berubah setelah dokumen terbit. Ditolak karena
salah pilih penyedia dari dropdown adalah salah ketik yang paling mudah terjadi
dan paling mahal akibatnya — justru itu yang paling perlu dapat diperbaiki.

## Konsekuensi

- Isi notifikasi `RealisasiMenungguPenerimaan` memuat nama penyedia, sehingga
  `update()` **wajib** memanggil `NotificationScanner::periksaRealisasi()`;
  tanpa itu lonceng gudang menyebut penyedia yang sudah tidak lagi benar.
- Setiap pengubahan menulis satu baris `audit_logs`. Riwayat perubahan sebuah
  realisasi karena itu terbaca di jejak audit, bukan di dokumennya sendiri —
  cukup untuk keperluan saat ini, dan bila kelak dibutuhkan versi per-perubahan,
  itu keputusan tersendiri.
- Realisasi batal berhenti lahir karena sebab administratif. Yang tersisa di
  daftar hanya pembatalan yang benar-benar mewakili kegagalan di lapangan —
  membuat angkanya jauh lebih berarti, dan melengkapi
  [ADR 0026](0026-agregat-tidak-menghitung-realisasi-batal.md).
