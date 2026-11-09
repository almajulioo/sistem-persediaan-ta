# ADR 0056 — Barang yang tidak diisi mengikuti stok sistem saat opname diposting

## Status

Diterima — 9 November 2026

Menggantikan sebagian
[ADR 0010](0010-alur-stok-opname-dan-penyesuaian-selisih.md): syarat posting
kedua, "seluruh barang sudah dicacah — tidak ada `stock_real` yang kosong",
dihapus. Aturan penyesuaian ADR 0010 (yang diterapkan hanyalah selisih hasil
cacah) tetap berlaku. Juga menghapus jenis notifikasi "Opname Siap Diposting"
dari [ADR 0013](0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md).

## Konteks

Narendra meminta: "buat agar barang yang tidak diisi nilai stok di gudang atau
dikosongkan, memiliki nilai stok current di sistem ketika stok opname di post,
jadi lebih mudah dan cepat hanya menginput stok barang yang berubah. berikan UI
yang informatif dan intuitif, agar petugas yang melakukan cacah tau."

Draf opname memuat seluruh barang aktif — katalog kantor berisi ribuan barang —
dan ADR 0010 menolak posting selama satu saja masih kosong. Petugas harus
mengetik angka untuk setiap barang, padahal sebagian besar cocok dengan stok
sistem.

Memeriksa layar pencacahan untuk perubahan ini menemukan dua cacat yang
**menjadi berbahaya** begitu barang kosong boleh diposting:

1. **`max_input_vars` PHP (bawaan 1000) memotong formulir diam-diam.** Layar
   lama mengirim satu isian `details[id][stock_real]` per barang. Dengan
   ribuan barang, isian di atas batas itu dibuang PHP sebelum Laravel
   melihatnya — tanpa galat. Di bawah ADR 0010 akibatnya membingungkan tetapi
   aman (posting ditolak karena "masih ada barang belum dicacah"). Di bawah
   aturan baru, barang yang terpotong akan **diposting sebagai sesuai**.
2. **Posting memakai data tersimpan, bukan isian di layar.** Teks "Simpan draf
   sebelum memposting" ada karena itu. Di bawah aturan baru, isian yang lupa
   disimpan juga akan diposting sebagai sesuai.

## Keputusan

### Aturan

Barang yang `stock_real`-nya kosong saat posting **dianggap sesuai stok
sistem**. `OpnamePoster` mengisi `stock_real = stock_stored` dan
`difference = 0`, sehingga `adjustment_amount = 0`, tidak ada mutasi, dan stok
akhirnya tetap stok sistem pada detik posting — persis yang diminta.

Hasil cacahnya disamakan dengan `stock_stored` (stok saat draf dibuat), **bukan**
`stock_at_posting`. Bila 20 unit keluar secara sah setelah draf dibuat (100 →
80), mengisi `stock_real = 80` membuat `difference = 80 − 100 = −20`, dan
posting menerapkan −20 lagi: stok menjadi 60, keluarnya terhitung dua kali.
Dengan `stock_stored`, selisihnya nol dan stok tetap 80.

Ini **bukan** "posting sebagian" yang ditolak ADR 0010: setiap barang tetap ada
di dokumen dan tetap punya keputusan — diisi petugas, atau mengikuti stok
sistem. Laporan per periode tetap utuh.

### Penanda: `counted_at`, tanpa migrasi

`counted_at` sejak awal hanya diisi saat petugas memasukkan angka. Baris yang
diisi sistem ketika posting dibiarkan tanpa waktu cacah — tidak ada yang
menghitungnya. Maka:

- `OpnameDetail::diisiPetugas()` = `counted_at !== null`
- `OpnameDetail::mengikutiStokSistem()` = `stock_real` terisi tetapi tidak diisi
  petugas
- `stokBerubahSejakDicacah()` kini mensyaratkan `diisiPetugas()`: baris yang
  mengikuti stok sistem tidak punya hasil cacah yang bisa berbeda dari stok
  akhirnya, jadi tidak ditandai dan tidak memicu notifikasi "Stok Bergerak
  Setelah Dicacah".
- `counted_at` kini berarti **saat angka itu dimasukkan**, bukan saat terakhir
  disimpan: hanya diperbarui bila `stock_real` berubah.
- `StockOpname::ringkasan()` menggantikan kunci `dicacah`/`belum_dicacah` dengan
  `diisi`/`tidak_diisi`. `siapDiposting()` dihapus.

### Simpan dan posting dalam satu kiriman

Rute `POST opname/{opname}/posting` (`opname.post`) dihapus. Posting dikirim
lewat `PUT opname/{opname}` dengan `aksi=posting`: controller menyimpan isian
di layar lebih dulu (transaksinya sendiri), lalu memanggil `OpnamePoster`. Bila
posting gagal — selisih tanpa alasan, stok minus — isian yang baru disimpan
tetap ada di draf dan petugas kembali ke layar pencacahan dengan pesannya.

Log aktivitas: baris `Ubah` ditulis **hanya bila ada baris yang benar-benar
berubah** (kalimatnya kini "N barang diubah, X dari Y barang diisi"), lalu baris
`Posting` dari `OpnamePoster`. Kiriman tanpa perubahan tidak dicatat, karena
tidak mengubah apa pun.

### Hanya baris yang berubah, sebagai satu JSON

Layar pencacahan mengirim baris yang berubah (dibanding yang tersimpan) sebagai
**satu** isian tersembunyi `hasil_cacah` berisi JSON. Satu isian tidak pernah
terpotong `max_input_vars`. `UpdateOpnameCountRequest::prepareForValidation()`
membukanya menjadi `details`, lalu validasinya sama seperti sebelumnya;
`details[...]` sebagai larik biasa tetap diterima. JSON yang tak terbaca ditolak
dengan pesan "Data pencacahan tidak terbaca". Baris yang tidak dikirim tidak
disentuh — juga mengurangi risiko dua tab menimpa isian satu sama lain.
Alasan dan keterangan pada baris yang tidak berselisih dibuang di server.

### Layar pencacahan

Ditulis ulang; tabelnya dirender di klien dari data JSON, 50 baris sekali
tampil ("Tampilkan lebih banyak"):

- **Placeholder setiap kotak Stok Fisik adalah stok sistemnya**, dan kolom
  Selisih menampilkan label **Ikut sistem** selama kotak kosong — kosong
  terlihat sebagai angka sistem, bukan barang yang terlewat.
- **Satu kalimat petunjuk** di atas tabel: "Isi Stok Fisik hanya untuk barang
  yang jumlahnya berbeda dari Stok Sistem. Barang yang dikosongkan dianggap
  sesuai dan mengikuti stok sistem saat diposting." Ini pengecualian sadar atas
  aturan "UI bersih" (1 Oktober 2026): Narendra meminta secara eksplisit agar
  petugas tahu.
- Kartu ringkasan berjalan: Total Barang, Diisi (sesuai · berselisih), Selisih
  Lebih / Kurang, **Ikut Stok Sistem**.
- Pencarian kode/nama dan penyaring **Semua / Diisi / Berselisih / Ikut Stok
  Sistem**, serta **Perlu Dilengkapi** (merah, hanya bila ada) untuk angka tidak
  sah atau selisih tanpa alasan/keterangan. Daftar hanya disaring ulang saat
  penyaring atau kata kunci berganti, supaya baris tidak lenyap di tengah
  pengetikan.
- Alasan dan keterangan baru muncul saat baris berselisih, berbingkai merah
  selama kosong. **Enter** berpindah ke alasan → keterangan bila berselisih,
  atau ke baris berikutnya.
- Kotak Stok Fisik berjenis teks `inputmode="numeric"`, bukan `number`, supaya
  roda tetikus tidak mengubah angka saat menggulir tabel.
- "N perubahan belum disimpan" di samping **Simpan Draf** (nonaktif bila tidak
  ada perubahan), dan peringatan peramban saat meninggalkan halaman dengan
  perubahan yang belum terkirim.
- **Posting Penyesuaian** membuka modal ringkasan: diisi petugas (sesuai /
  berselisih), dan **berapa barang yang akan mengikuti stok sistem**, beserta
  peringatan bahwa posting tidak dapat dibatalkan. Tombol "Ya, Posting"
  nonaktif selama ada yang perlu dilengkapi, dengan tautan "Tampilkan".
- Teks "Simpan draf sebelum memposting" dihapus — penyebabnya sudah tidak ada.
- Setelah validasi gagal, isian dipulihkan dari JSON kiriman dan penyaring
  langsung "Perlu Dilengkapi".

### Halaman hasil, daftar, laporan

Halaman hasil: kartu **Diisi Petugas**, **Ikut Stok Sistem** (draf: Belum
Diisi), Barang Berselisih, Selisih Lebih / Kurang; penyaring
`?baris=diisi|berselisih|tidak_diisi`; angka stok fisik baris yang mengikuti
sistem tampil redup berlabel "Ikut sistem". Daftar opname: kolom Pencacahan
"X / Y diisi". Laporan Excel ([ADR 0055](0055-laporan-stok-opname-dalam-excel.md)):
kolom Pencacahan dan catatan penjelasnya.

### Notifikasi

- **Opname Siap Diposting dihapus.** Syaratnya ("seluruh barang sudah dicacah")
  tidak ada lagi: setiap draf selalu dapat diposting, dan sistem tidak dapat
  tahu kapan pencacahan selesai. Migrasi `2026_11_09_000004` menghapus barisnya
  lebih dulu — `type` di-cast ke enum, dan nilai yang tak dikenal akan
  menggagalkan setiap halaman yang menampilkan lonceng.
- **Draf Opname Menggantung** kini berlaku untuk setiap draf berumur lebih dari
  7 hari (dulu dikecualikan bila sudah lengkap), dengan kalimat "belum diposting
  (X dari Y barang diisi)".
- **Opname Diposting** menyebut "X dari Y barang diisi, sisanya mengikuti stok
  sistem".

### Data contoh

Opname Triwulan II yang sudah diposting kini dibuat dengan cara baru: delapan
barang diisi (tiga berselisih, lima sesuai), sisanya kosong dan mengikuti stok
sistem.

## Alternatif yang ditolak

**`stock_real = stock_at_posting`.** Menerapkan ulang mutasi sah sejak draf
dibuat (lihat contoh 100 → 80 → 60 di atas). Aturan ADR 0010 yang sama yang
menolak "memaksa stok akhir sama dengan hasil cacah".

**`stock_real` tetap kosong setelah posting.** Merusak invarian "opname selesai
= setiap baris punya hasil cacah"; setiap pembaca `stock_real` dan `difference`
butuh kasus khusus, dan laporan menampilkan kotak kosong untuk angka yang
sebenarnya diterima.

**Kolom penanda baru** (mis. `mengikuti_sistem` boolean). `counted_at` sudah
menyatakannya dengan makna yang jujur, tanpa migrasi pada tabel yang sudah
berisi data.

**Tombol "Tandai semua sesuai"** yang mengisi setiap kotak kosong dengan stok
sistem, mempertahankan syarat ADR 0010. Menulis ribuan baris, dan menghapus
beda antara angka yang diketik dan yang diasumsikan — beda yang justru ingin
dibaca pemeriksa.

**Mempertahankan rute posting terpisah dan memblokir posting selama ada
perubahan belum disimpan.** Aman, tetapi setiap posting butuh simpan dulu; yang
diminta justru lebih cepat.

**Menaikkan `max_input_vars` di php.ini.** Kebenaran stok akan bergantung pada
konfigurasi server; bila lupa disetel, stok salah tanpa galat.

**Paginasi di server untuk layar pencacahan.** Isian yang belum disimpan akan
hilang setiap pindah halaman, atau harus disimpan per halaman.

**Mendefinisikan ulang "Siap Diposting".** Tidak ada kondisi yang bermakna
untuk menggantikannya; pengingat yang tersisa adalah draf yang belum juga
diposting.

## Konsekuensi

- **Sistem tidak lagi dapat membedakan barang yang dicek dan cocok dari barang
  yang terlewat.** Memposting adalah pernyataan petugas bahwa yang kosong
  sesuai. Karena itu setiap baris tetap menyatakan asal angkanya — layar hasil
  dan laporan menandai "Ikut sistem" / "Mengikuti stok sistem" — supaya pembaca
  dapat membedakan angka yang diketik dari yang diasumsikan.
- Draf yang tidak diisi sama sekali boleh diposting: hasilnya opname tanpa
  selisih dan tanpa mutasi. Modal konfirmasi menampilkan angkanya.
- Kalimat log aktivitas pengisian berubah; baris log lama tetap memakai kalimat
  lama ("X dari Y barang tercacah").
- Tidak ada berkas Alma yang tersentuh. Tabel yang berubah perilakunya
  (`opname_details`, `user_notifications`) milik sub sistem ini.
