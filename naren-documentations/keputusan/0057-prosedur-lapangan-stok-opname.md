# ADR 0057 — Prosedur lapangan stok opname: wajib diisi kecuali insidental, pembanding pada waktu cacah, ruang tanda tangan Kasubbag, dan satu draf aktif

## Status

Diterima — 9 November 2026

Menggantikan sebagian:

- [ADR 0056](0056-barang-tidak-diisi-mengikuti-stok-sistem.md) — "barang kosong
  mengikuti stok sistem" kini hanya untuk opname **insidental**; notifikasi
  "Opname Siap Diposting" dipulihkan untuk opname lainnya.
- [ADR 0010](0010-alur-stok-opname-dan-penyesuaian-selisih.md) — syarat posting
  "tidak ada `stock_real` yang kosong" berlaku lagi (kecuali insidental), dan
  `stock_stored` tidak lagi berarti "stok saat draf dibuat" melainkan "stok
  sistem pada waktu barang itu dihitung".
- [ADR 0055](0055-laporan-stok-opname-dalam-excel.md) — Excel opname yang sudah
  diposting kini ditutup satu ruang tanda tangan Kepala Subbagian Umum dan TI.

## Konteks

Dalam percakapan peninjauan, Narendra sepakat bahwa dua aturan yang berlaku
tidak cocok dengan opname yang sesungguhnya:

1. **"Kosong = sesuai" (ADR 0056) tidak cocok untuk opname berkala.** Opname
   triwulan, semester, atau tahunan harus memastikan *setiap* barang secara
   fisik. Bila baris kosong boleh diposting, sistem tidak dapat membedakan
   barang yang dicek dan cocok dari barang yang terlewat — padahal justru itu
   yang ditanyakan pemeriksa. Opname insidental (pencacahan di luar jadwal,
   biasanya sebagian barang) tetap cocok dengan aturan ADR 0056.

2. **Salinan stok saat draf dibuat (ADR 0010) merusak pencacahan yang
   berlangsung beberapa hari sementara gudang tetap melayani.** `store()`
   menyalin `stock_stored` untuk setiap barang, `difference = stock_real −
   stock_stored`, lalu posting menerapkan `difference` di atas
   `stock_at_posting`. Mutasi di antara pembuatan draf dan penghitungan fisik
   sebuah barang menghasilkan selisih palsu yang lalu **diterapkan dua kali**.
   Contoh: draf mencatat 5, tiga diserahkan (sistem 2), petugas menghitung 2
   dan mengetik 2 → "selisih −3" dengan alasan karangan. Posting lalu menolak
   seluruh opname (`InsufficientStockException`) atau menyisakan stok 3 terlalu
   rendah. Penerimaan selama pencacahan berbuat sama ke arah sebaliknya.

Rencana awal untuk butir 2 adalah membandingkan dengan stok sistem **saat baris
disimpan**. Ditanya bagaimana petugas mencacah di lapangan, Narendra menjawab:
**di lembar kertas, lalu diketik kemudian.** Dengan cara itu "saat disimpan"
bukan "saat dihitung": lembar pagi yang diketik sore tetap dibandingkan dengan
stok sore, dan bugnya kembali.

Dua hal lain diputuskan bersamaan: tidak ada tahap persetujuan Kasubbag untuk
posting, tetapi laporannya memuat ruang tanda tangan Kasubbag; dan hanya satu
draf boleh berjalan dalam satu waktu.

## Keputusan

### 1. Setiap barang wajib diisi — kecuali opname insidental

- Satu aturan di satu tempat: `PeriodeOpname::bolehKosong()` (benar hanya untuk
  `Insidental`), diteruskan oleh `StockOpname::bolehKosong()`. Tidak ada
  pembandingan enum dengan tangan di tempat lain.
- `OpnamePoster::pastikanSiap()` menolak opname yang tidak `bolehKosong()`
  selama masih ada `stock_real` kosong, dengan
  `OpnameTidakSiapException::masihAdaYangBelumDiisi($jumlah)` — "Masih ada N
  barang yang belum diisi. Lengkapi seluruh hasil cacah sebelum memposting."
  Cabang pengisi baris kosong di `terapkan()` kini hanya tercapai oleh opname
  insidental.
- **Tidak ada tombol "Sesuai" per baris** (ditolak Narendra): petugas mengetik
  angkanya, juga bila sama dengan stok sistem.
- Layar pencacahan opname berkala:
  - kotak Stok Fisik yang kosong **tanpa placeholder** (keputusan Narendra) —
    angka stok sistem abu-abu di dalam kotak membuat baris yang belum diputuskan
    tampak terisi dan menggiring petugas sekadar "mengonfirmasi" angka sistem.
    Kolom Stok Sistem tetap tampil (pencacahan buta **tidak** dipilih);
  - kartu dan chip **"Belum Diisi"**, baris kosong bertanda "—";
  - modal posting menampilkan "Belum diisi: N barang", kotak merah "N barang
    belum diisi." dengan tautan **Tampilkan** yang menyaring baris kosong, dan
    **"Ya, Posting" terkunci** selama ada baris kosong;
  - kalimat petunjuk "Isi Stok Fisik hanya untuk barang yang jumlahnya
    berbeda…", pil "Ikut sistem", kartu/chip "Ikut Stok Sistem", dan baris
    modal "Mengikuti stok sistem" **hanya tampil pada opname insidental**.
- Halaman hasil dan Excel memakai `StockOpname::kosongMengikutiStokSistem()`
  untuk labelnya: "Ikut/Mengikuti Stok Sistem" hanya pada opname insidental
  yang sudah diposting (atau opname lama yang diposting di bawah ADR 0056
  dengan baris kosong); selain itu "Belum Diisi".
- **"Opname Siap Diposting" dipulihkan** (keputusan Narendra) persis seperti
  sebelum ADR 0056 — keadaan, penerima pembuat draf, saling eksklusif dengan
  "Draf Opname Menggantung", hilang saat diposting — tetapi **hanya untuk
  opname selain insidental**, lewat `StockOpname::siapDiposting()`. Pada opname
  insidental "semua terisi" tidak bermakna. Peristiwa "telah diposting" menyebut
  "N barang dicacah", atau "x dari y barang diisi, sisanya mengikuti stok
  sistem" bila memang ada baris yang mengikutinya.

### 2. Pembanding adalah stok sistem pada waktu barang dihitung ("Dihitung pada")

- Layar pencacahan mendapat isian **"Dihitung Pada"** (tanggal dan jam, disalin
  dari lembar kertas) untuk baris-baris yang disimpan bersamaan.
- **Wajib** pada setiap simpan atau posting yang mengubah angka Stok Fisik,
  **tanpa isian bawaan**, dan **kosong lagi setelah setiap simpan** (keputusan
  Narendra): isian bawaan "sekarang" yang lupa diubah mengembalikan bug semula.
  Mengosongkan baris atau mengubah alasan/keterangan saja tidak memerlukannya.
- Rentang yang sah: **pukul 00:00 Tanggal Cacah** (`opname_date`) sampai saat
  ini (keputusan Narendra) — petugas boleh menghitung di kertas sebelum drafnya
  dibuat. Waktu di luar rentang atau tak terbaca ditolak.
- Pembanding tiap baris = **stok sistem pada menit itu** menurut buku mutasi:
  stok kini dikurangi jumlah `amount` mutasi yang tercatat sesudah menit itu
  (`StockLedger::stokPada()`, metode baca baru; `record()` tidak berubah).
  Mutasi yang tercatat **di dalam** menit itu dihitung sudah terjadi —
  "dihitung pukul 08:30" berarti keadaan sampai pukul 08:30 lewat. Karena buku
  mutasi immutable dan diurutkan menurut waktu catat (ADR 0005), stok pada
  menit yang sudah lewat tidak berubah lagi.
- Skema: **tanpa migrasi.** `stock_stored` dipakai ulang dengan makna baru
  "stok sistem pada waktu baris ini dihitung", dan `counted_at` berisi waktu
  "Dihitung pada" itu (bukan lagi saat disimpan). Setiap rumus dan
  `stokBerubahSejakDicacah()` tetap berlaku. Salinan saat draf dibuat hanya
  nilai awal yang diganti ketika barisnya diisi.
- Pembanding dihitung ulang **hanya bila angka barisnya berubah** (penjaga
  `stock_real` berubah yang sama dengan `counted_at` sejak ADR 0056). Angka sama
  yang dikirim ulang dengan jam lain tidak mengubah apa pun; untuk membetulkan
  jamnya saja, kosongkan lalu isi ulang barisnya.
- **Pembanding tidak pernah diambil dari kiriman** — itu membuka jalan
  menghindari alasan wajib. Layar menghitungnya sendiri (data mutasi sejak 00:00
  Tanggal Cacah disertakan per baris) dan mengirim **stok sistem yang
  ditampilkannya** (`stok_sistem`) untuk setiap angka baru. Server menghitung
  ulang dari buku mutasi — di dalam transaksi, dengan baris barang terkunci —
  dan bila berbeda (ada mutasi yang tercatat setelah halaman dibuka) baris itu
  ditolak: "Stok sistem X pada 09 Nov 2026 11:00 ternyata 2, bukan 5. Periksa
  lagi hasil cacahnya." Kewajiban alasan dinilai terhadap pembanding server.
- Layar pencacahan menampilkan, di kolom Stok Sistem: untuk angka baru, stok
  pada "Dihitung Pada" (atau stok kini selama jamnya belum diisi); untuk baris
  yang sudah dicacah, pembanding tersimpannya. Bila stok kini berbeda,
  ditampilkan di bawahnya sebagai data ("kini 31"); jam cacah tersimpan
  menjadi tooltip data. Halaman hasil dan Excel draf menampilkan stok kini untuk
  baris yang belum dicacah (`OpnameDetail::stokSistem()`), bukan salinan basi.
- Baris kosong opname insidental: saat posting pembandingnya disamakan dengan
  `stock_at_posting` (selisih tetap 0), sehingga "Stok Sistem" di laporan bukan
  salinan saat draf dibuat.
- Mutasi **sesudah** waktu cacah tetap seperti ADR 0010: tidak disesuaikan,
  baris ditandai "stok berubah sejak dicacah", peristiwa
  `StokBergerakSetelahDicacah`.
- Judul kolom tetap **"Stok Sistem"**, dan **tidak ada** kolom "Dihitung Pada"
  per baris di Excel maupun halaman hasil (keputusan Narendra). Waktunya
  tersimpan di `counted_at` dan disebut di log aktivitas: "Mengubah hasil cacah
  … (3 barang diubah, dihitung pada 09 Nov 2026 08:30, 10 dari 120 barang
  diisi)".

### 3. Tanpa tahap persetujuan; ruang tanda tangan Kasubbag di Excel

- Posting tetap tindakan Pengelola Gudang. **Tidak ada persetujuan Kasubbag.**
- Excel opname yang **sudah diposting** ditutup satu ruang tanda tangan di
  kolom Pencacahan–Keterangan (digabung, rata tengah):

  ```
  Bandar Lampung, 9 November 2026      ← tanggal posted_at
  Mengetahui,
  Kepala Subbagian Umum dan TI



  (....................................)
  ```

- **Nama tidak diambil dari akun mana pun** — dibiarkan titik-titik untuk
  ditulis tangan. Narendra semula meminta nama Kasubbag terbaru yang dibekukan
  saat posting, lalu mengubahnya saat ditanya akun mana yang dihitung. Karena
  itu tidak ada kolom baru, tidak ada pembekuan, tidak ada pencarian akun.
- **Draf tidak mendapat ruang tanda tangan**: belum ada yang perlu
  ditandatangani (tombol unduhnya pun hanya tampil pada opname Selesai).
- Tidak ada ruang tanda tangan Pengelola Gudang.
- Ejaan "Umum dan TI" mengikuti label peran, unit kerja, dan jabatan akun
  contoh; TI/TU masih butir terbuka 3.

### 4. Satu draf aktif dalam satu waktu

- `StockOpnameController::store()` menolak selama ada draf, dengan pemeriksaan
  **di dalam transaksi `KirimanFormulir::sekali()`** — sesudah pemeriksaan
  token, sehingga formulir yang terkirim dua kali tetap diarahkan ke draf yang
  baru dibuatnya. Penolakan dilempar sebagai `DrafOpnameMasihAdaException` dan
  diterjemahkan menjadi arahan ke layar pencacahan draf itu dengan pesan "Masih
  ada draf … yang belum diposting. Posting atau hapus draf ini sebelum memulai
  opname baru."
- Daftar opname menampilkan **"Lanjut Cacah <periode>"** di tempat "Mulai
  Opname" selama ada draf; halaman Mulai Opname menggantikan formulirnya dengan
  kalimat terkunci dan tombol **Lanjut Cacah**.
- Opname untuk periode yang sama tetap boleh berulang (ADR 0012), asal
  berurutan.
- **Hanya penjagaan aplikasi** (keputusan Narendra): petugas gudang praktis
  satu orang, dan token kiriman sudah menahan kiriman ganda dari formulir yang
  sama.

## Alternatif yang ditolak

**Tombol "Sesuai" per baris** untuk opname berkala. Ditolak Narendra.

**Pencacahan buta** (kolom Stok Sistem disembunyikan). Tidak dipilih; yang
diambil hanyalah menghapus placeholder di kotak kosong.

**Pembanding = stok sistem saat baris disimpan** (rencana awal). Benar hanya
bila petugas mengetik sambil menghitung; petugas di lapangan mencacah di kertas.

**Memilih per barang "dihitung sebelum/sesudah mutasi X"** untuk barang yang
stoknya bergerak. Tanpa isian jam, tetapi petugas harus ingat urutannya untuk
setiap barang, dan layarnya jauh lebih ramai.

**"Saat disimpan" ditambah prosedur** (lembar diketik sebelum barang di rak itu
dilayani lagi). Sistem tidak dapat mendeteksi pelanggarannya.

**"Dihitung Pada" terisi jam sekarang** dan dapat diubah. Lebih sedikit ketikan,
tetapi bila lupa diubah, lembar pagi dibandingkan dengan stok sore.

**Batas awal = saat draf dibuat.** Menolak penghitungan di kertas yang dimulai
sebelum draf dibuat di sistem pada hari yang sama.

**Pembanding dari kiriman klien.** Klien dapat mengatur angkanya supaya selisih
lenyap dan alasan tidak wajib lagi.

**Kolom baru di samping salinan saat draf dibuat.** Tidak ada pembaca yang
membutuhkan salinan itu; memakai ulang `stock_stored` menjaga setiap rumus.

**Judul "Stok Sistem Saat Dicacah"** dan **kolom "Dihitung Pada" per baris** di
Excel/halaman hasil. Tidak dipilih Narendra.

**Persetujuan posting oleh Kasubbag.** Ditolak Narendra; posting tetap tindakan
Pengelola Gudang.

**Nama Kasubbag dari akun `kasubbag_umum_ti` terbaru, dibekukan saat posting**
(kolom `stock_opnames.kasubbag_name`). Rencana awal; ditarik Narendra — nama
ditulis tangan.

**Ruang tanda tangan Pengelola Gudang**, dan **ruang tanda tangan pada draf**.

**Penjaga di basis data** (kolom nullable unique yang hanya terisi selama Draf,
trik ADR 0031). Menahan balapan dua tab, tetapi butuh migrasi dan mengubah
banyak fixture; tidak sepadan untuk satu petugas.

**Satu ADR per perubahan.** Narendra memilih satu ADR gabungan.

## Konsekuensi

- **Prosedur lapangan berubah:** setiap lembar cacah wajib mencantumkan tanggal
  dan jam penghitungan. Ketepatan pembanding sebaik jam yang ditulis itu.
- Petugas dapat memilih jam di dalam rentang yang sah; jam itu menentukan
  pembanding. Rentangnya dibatasi (Tanggal Cacah sampai sekarang) dan jam yang
  dipakai tercatat di log aktivitas, tetapi sistem tidak dapat membuktikan
  bahwa jam di lembar kertas benar.
- Baris yang dicacah sebelum perubahan ini tetap memakai salinan saat draf
  dibuat sebagai pembanding sampai angkanya diubah. Data lokal hanya data
  contoh.
- Opname lama yang diposting di bawah ADR 0056 dengan baris kosong tetap tampil
  apa adanya ("Mengikuti stok sistem"), apa pun periodenya.
- `StockLedger` — berkas kontrak lintas sub sistem — mendapat satu metode
  **baca** (`stokPada()`); `record()` tidak berubah, jadi pemakaian Alma tidak
  terpengaruh.
- Contoh data: opname Triwulan II yang diposting kini mengisi setiap barang
  (tiga berselisih, sisanya sesuai); draf Triwulan III tetap sebagian terisi.
- Uji: 910 → 942. Tidak ada berkas Alma yang tersentuh, dan tidak ada migrasi.
