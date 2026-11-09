# ADR 0051 — BAST pengadaan digital dengan TTE QR; stok masuk setelah kedua pihak bertanda tangan

## Status

Diterima — 9 November 2026

Menggantikan sebagian:

- [ADR 0008](0008-penerimaan-barang-oleh-pengelola-gudang.md): konfirmasi gudang
  kini terdiri dari hasil pemeriksaan dan TTE Pihak Kedua.
- [ADR 0018](0018-berkas-unggahan-di-disk-privat.md): pindaian BAST bertanda
  tangan menjadi opsional.
- [ADR 0021](0021-realisasi-dan-penerimaan-pengadaan.md): stok masuk saat TTE
  kedua, bukan saat konfirmasi.
- [ADR 0042](0042-bast-pengadaan-format-resmi-bpk.md): nomor BAST diisi sebelum
  TTE dan terkunci olehnya, serta referensi mutasi kembali ke nomor BAST.

## Konteks

Sampai ADR ini, stok bertambah begitu Pengelola Gudang menekan "Konfirmasi
Penerimaan". BAST baru diberi nomor saat dicetak pertama kali (ADR 0042), lalu
ditandatangani basah, dipindai, dan diunggah, sementara notifikasi
`BastBelumDiunggah` terus menagih pindaiannya.

Narendra meminta transisi ke paperless, dengan isi permintaan sebagai berikut:

- Pada tahap Menunggu Penerimaan, **kedua pihak** (Pejabat Pengadaan dan
  Pengelola Gudang) menekan tombol untuk membubuhkan **QR sebagai TTE** di kolom
  tanda tangan masing-masing pada BAST digital.
- Nomor BAST dibuat lebih dulu.
- **Mutasi barang baru masuk setelah keduanya bertanda tangan.**
- BAST selalu bisa dilihat di setiap tahap, dari belum bertanda tangan sampai
  lengkap.
- Menu unduh "BAST kosong" tetap tersedia, termasuk setelah lengkap.
- Unggah berkas pendukung tetap ada sebagai dokumentasi opsional.
- Tampilannya jelas, tidak tersembunyi.

Sub sistem permintaan milik Alma sudah memakai QR pada persetujuan Nota Dinas
(`RequestDocumentGenerator`, `request_documents.verification_token_hash`, rute
publik `/verifikasi/nota-dinas/...`). Pendekatan itu diperiksa lebih dulu
sebelum memutuskan dipakai ulang atau diubah.

Konsepnya dibahas di mode plan sebelum kode ditulis. Empat pertanyaan
dijawab Narendra:

| Soal | Jawaban |
|---|---|
| Kata sandi saat TTE | **Tidak**; cukup dialog konfirmasi |
| Admin membubuhkan TTE | **Tidak boleh** (rekomendasi diterima) |
| Siapa Pihak Pertama | **Pejabat Pengadaan mana pun** (rekomendasi diterima) |
| Halaman verifikasi | **Publik, isi lengkap** (rekomendasi diterima) |

## Analisis QR Nota Dinas

**Yang dipakai ulang:**

- paket `endroid/qr-code` 6.0 yang sudah terpasang;
- PNG data URI untuk dompdf, koreksi galat High;
- pola rute verifikasi publik dengan kode acak.

**Yang dibuat berbeda,** beserta alasannya. Berkas Alma tidak disentuh.

1. **Token di-hash → kode disimpan apa adanya.** Token yang hanya disimpan
   sebagai SHA-256 tidak bisa dipulihkan, sehingga PDF harus dibuat sekali
   lalu disimpan, dan setiap render ulang menerbitkan QR baru. Pada Nota Dinas,
   menomori ulang mematikan QR lama tanpa jejak. BAST di sini dirender kapan
   saja dengan QR yang sama. Hash hanya melindungi dari bocornya basis data,
   padahal yang ditampilkan halaman verifikasi pun isi basis data itu.
2. **Data hidup → isi dibekukan.** Verifikasi Nota Dinas membaca `fullname` dan
   `jabatanKepala()` unit saat ini, sehingga isinya berubah bila data akun
   berubah setelah ditandatangani.
3. **404 → "tidak berlaku" beserta alasannya.** QR dari cetakan yang sudah
   digantikan tidak bisa dibedakan dari QR palsu bila keduanya hanya mendapat
   404.
4. **Metadata → isi dokumen.** QR yang disalin ke dokumen lain tetap tampak
   sah bila halaman verifikasi hanya menyebut nomor dan penyetuju.
5. **Rute publik tanpa batas → `throttle:60,1`.**
6. **URL dari host permintaan → dari `APP_URL`.** BAST yang dicetak lewat
   `localhost` menanam alamat yang tidak bisa dibuka dari ponsel.
7. **Admin bisa menyetujui atas nama Kepala Unit** (`RequestWorkflowService`
   baris 106) → di sini Admin tidak bisa TTE. Dicatat sebagai saran untuk
   Alma, bukan diubah.

## Keputusan

### 1. Empat langkah, semuanya di dalam `MenungguPenerimaan`

Tidak ada status realisasi baru. Kemajuan BAST terbaca dari datanya sendiri
(`BastPengadaan::tahap()`).

| # | Langkah | Oleh | Hasil |
|---|---|---|---|
| 1 | Hasil pemeriksaan | Pengelola Gudang (`kelola-gudang`) | Draf `procurement_receipts` + `procurement_details.amount_checked`. **Stok belum berubah.** |
| 2 | Nomor BAST | Pejabat Pengadaan atau Pengelola Gudang (`isi-nomor-bast`) | Baru bisa diisi setelah langkah 1. Tetap tanpa saran (ADR 0042). |
| 3 | TTE Pihak Pertama | Pejabat Pengadaan (Pelaksana Belanja) | Urutan 3 dan 4 bebas; keduanya mensyaratkan langkah 1–2. |
| 4 | TTE Pihak Kedua | Pengelola Gudang (Petugas Persediaan) | TTE yang terakhir memasukkan stok di transaksi yang sama. |

Pada langkah 4:

- jumlah diambil dari **isi yang ditandatangani**, bukan dari kolom hidup;
- `amount_received` diisi;
- mutasi `Masuk` tercatat lewat `StockLedger` dengan `recordedBy` = penanda
  tangan Pihak Kedua (ADR 0008), siapa pun yang menandatangani terakhir;
- status menjadi `Diterima`.

**Pihaknya diturunkan dari peran di server** (`PihakBast::dariPeran()`), tidak
pernah dari isian formulir. Pejabat Pengadaan mana pun boleh menjadi Pihak
Pertama, dan Pengelola Gudang mana pun boleh menjadi Pihak Kedua. Identitas yang
tercetak mengikuti penanda tangannya.

### 2. Isi dibekukan saat TTE pertama

`procurement_receipts.content` (JSON) dan `content_hash` (SHA-256) diisi saat
TTE pertama. Sesudahnya, PDF dan halaman verifikasi dirender dari salinan ini,
sehingga mengganti nama barang di master atau jabatan akun tidak mengubah BAST
yang sudah ditandatangani.

Kunci objek diurutkan bertingkat sebelum di-hash: kolom JSON MySQL
mengurutkan ulang kuncinya, sehingga JSON yang dibaca kembali tidak selalu
sama bunyinya.

Setiap TTE menyimpan sidik yang ia tandatangani. TTE **sah** bila tiga hal
terpenuhi:

- tidak dicabut;
- sidiknya sama dengan sidik penerimaan;
- `sidik(content)` masih sama dengan `content_hash`.

Isi yang diutak-atik di basis data membuat semua TTE-nya tidak sah dan
menolak TTE berikutnya (PP 71/2019 Pasal 60: perubahan setelah
penandatanganan harus dapat diketahui).

### 3. Mengubah data mencabut TTE, bukan dilarang

Pola ini sama dengan ADR 0030: menyimpan perubahan berarti meminta tanda tangan
ulang.

| Perubahan | Alasan pencabutan |
|---|---|
| Hasil pemeriksaan | "Hasil pemeriksaan diubah" |
| Nomor BAST | "Nomor BAST diubah" |
| Realisasi diubah | "Realisasi diubah" |
| Realisasi dibatalkan | "Realisasi dibatalkan" |

- Bila salah satunya berubah setelah ada TTE, TTE itu **dicabut**
  (`revoked_at` + `revoke_reason`), snapshot dikosongkan, dan audit aksi itu
  menyebutnya ("…; TTE {nama} dicabut").
- Mengirim ulang nilai yang sama tidak mencabut apa pun.
- Pada realisasi yang diubah atau dibatalkan, **hasil pemeriksaan
  dipertahankan**, karena fisik barangnya tidak berubah. Barang tambahan yang
  belum diperiksa membuat langkah 1 belum lengkap lagi.
- Draf penerimaan tidak pernah dihapus.
- Baris TTE yang dicabut **disimpan**, supaya QR pada cetakan lama tetap bisa
  menjelaskan sebabnya.
- Setelah kedua TTE lengkap, pemeriksaan, nomor, dan TTE terkunci.

### 4. Skema

Semuanya tabel milik Naren; tidak ada tabel bersama yang berubah.

- `procurement_details.amount_checked` (unsignedInteger, nullable).
  `amount_received` tetap berarti "sudah masuk stok", sehingga laporan, dasbor,
  dan kolom "Diterima" tidak berubah makna.
- `procurement_receipts.content` (json) dan `content_hash` (char 64).
  "Ada baris penerimaan" kini tidak lagi berarti "barang sudah diterima":
  laporan PDF/CSV menampilkan nomor dan tanggal BAST hanya bila status
  realisasinya `Diterima`.
- Tabel baru `procurement_receipt_signatures`:

  | Kolom | Isi |
  |---|---|
  | `signature_id` | PK |
  | `procurement_receipt_id`, `user_id` | FK, restrict |
  | `party` | enum `PihakBast` |
  | `signer_name`, `signer_nip`, `signer_position` | identitas disalin saat TTE |
  | `content_hash` | sidik isi yang ditandatangani |
  | `verification_code` | 40 karakter acak, unik |
  | `signed_at` | `useCurrent()`, karena gotcha MariaDB |
  | `revoked_at`, `revoke_reason` | pencabutan |

  "Satu TTE aktif per pihak" dijaga kode di bawah kunci baris realisasi, bukan
  indeks unik, karena baris yang dicabut tetap ada.
- `User::RESTRICTED_RELATIONS` mendapat `bastSignatures` (ADR 0003): akun yang
  pernah bertanda tangan diarsipkan, tidak dihapus.

### 5. Otorisasi

- **Gate baru `tte-bast`** untuk Pejabat Pengadaan dan Pengelola Gudang.
- **`Gate::before` tidak meloloskan Admin untuk `tte-bast` saja.** Ini
  satu-satunya pengecualian. TTE mengikat identitas pribadi salah satu pihak;
  Admin (staf TI) bukan pihak mana pun, dan bila lolos ia bisa menandatangani
  kedua sisi sendirian.
- **Admin** tetap membuka halaman, BAST, dan BAST kosong, serta tetap boleh
  mengisi nomor dan hasil pemeriksaan lewat gate yang sudah ada.
- **Kasubbag** membaca dan mencetak saja (ADR 0041).

Tanpa kata sandi saat TTE (pilihan Narendra). Atribusinya bertumpu pada sesi
login, ditopang `EnsureUserIsActive`, `AuthenticateSession`, dan IP pada
`audit_logs`.

### 6. Rute

| Rute | Gate |
|---|---|
| `POST pengadaan/{procurement}/bast/tte` | `tte-bast` |
| `GET pengadaan/{procurement}/bast/kosong` | `akses-pengadaan` |
| `GET verifikasi/bast/{kode}` | publik, `throttle:60,1`, tanpa batasan pola supaya kode salah ketik pun mendapat halaman yang jelas |

`GET …/bast` kini terbuka sejak `MenungguPenerimaan`. `PATCH …/bast` (nomor)
pada BAST digital kembali ke halaman detail, sedangkan pada BAST lama tetap
langsung mencetak.

Formulir hasil pemeriksaan **tidak** memakai `KirimanFormulir` (ADR 0046): satu
penerimaan per realisasi (indeks unik) ditambah upsert di bawah kunci membuat
kiriman ulang hanya menimpa nilai yang sama.

### 7. PDF

Satu template dirender dari `BastPengadaan::untukCetak()`.

- Yang belum terisi (nomor, kalimat tanggal, jumlah) dicetak sebagai
  titik-titik, sehingga BAST bisa dilihat di setiap tahap.
- Sebelum pemeriksaan, lampiran memuat semua barang dengan jumlah kosong.
- Kolom yang sudah ber-TTE berisi QR sekitar 1,9 cm di ruang tanda tangan yang
  sama, ditambah "Ditandatangani secara elektronik" dan waktunya.
- Bila ada TTE, satu baris catatan kecil di bawah tabel tanda tangan.
- Versi kosong berisi data dan nama yang sama, tanpa QR.

### 8. Halaman verifikasi

Halaman verifikasi berdiri sendiri, tanpa layout aplikasi dan tanpa Vite. Ia
memakai token warna, mode gelap, dan lebar ponsel; di ponsel, rincian barang
bertumpuk supaya jumlah dan nilai tidak tersembunyi di balik gulir tabel.

| Keadaan | Isi |
|---|---|
| Sah | Penanda tangan, dokumen, status kedua pihak, rincian barang, total, kekurangan kiriman, sidik dokumen |
| Tidak berlaku | Dicabut beserta tanggal dan alasannya, atau "isi dokumen tidak cocok". Isinya tidak ditampilkan |
| Tidak dikenal | Status 404 |

### 9. Mutasi kembali merujuk nomor BAST

ADR 0042 memindahkan `reference_number` ke `judul()` karena nomornya baru ada
setelah mutasi tercatat. Kini nomornya sudah ada dan terkunci oleh TTE sebelum
mutasi, jadi `reference_number` kembali berisi nomor BAST. Catatan mutasinya:
"Penerimaan barang dari {penyedia} sesuai BAST {nomor} ({judul})."

### 10. Notifikasi dan dasbor

- **`RealisasiMenungguPenerimaan`** hanya berlaku selama hasil pemeriksaan
  belum lengkap.
- **Keadaan baru `BastMenungguTandaTangan`** (Perhatian):
  - satu baris per pihak, dengan dedupKey `{id}:{pihak}`;
  - penerimanya hanya pemegang peran pihak itu, tanpa Admin;
  - berlaku sejak pemeriksaan lengkap sampai pihak itu ber-TTE.
- **`PenerimaanDikonfirmasi`** terbit saat TTE terakhir.
- **`BastBelumDiunggah`** hanya untuk BAST lama.
- **Butir terbuka 18 ditutup.** Posting kini memanggil
  `NotificationScanner::periksaBarang()` per barang setelah transaksi (aturan
  7). Ini ikut dikerjakan karena posting dipindah ke sini, dan disebut di
  rencana yang disetujui.
- **Dasbor (ADR 0040).** Antrean kerja mendapat "BAST menunggu TTE Anda" hanya
  bagi penanda tangannya sendiri. Tautannya menuju penyaring daftar `tte`.
  Definisinya satu (`Procurement::menungguTte()` / `scopeMenungguTte()`),
  dipakai bersama oleh notifikasi, penyaring, dan dasbor.

### 11. BAST lama

Realisasi yang sudah `Diterima` sebelum ADR ini tidak punya baris TTE
(`Procurement::bastLama()`). Perilakunya tetap seperti ADR 0042:

- kartu penerimaan lama;
- cetak dengan nomor yang wajib diisi lebih dulu;
- nomor terkunci setelah pindaian diunggah;
- `BastBelumDiunggah`.

## Posisi hukum

Ini **TTE tidak tersertifikasi** menurut PP 71/2019 Pasal 59–60. QR-nya
menunjuk halaman verifikasi aplikasi ini, bukan sertifikat elektronik BSrE/BSSN.
Unsur Pasal 60 yang dipenuhi:

- penanda tangan teridentifikasi (akun, dengan identitas yang disalin);
- persetujuan dinyatakan lewat tombol dan dialog;
- perubahan setelah penandatanganan dapat diketahui (sidik dan pencabutan).

Unsur "data pembuatan TTE dalam kuasa penanda tangan" hanya sekuat sesi
login, karena kata sandi tidak diminta ulang. Integrasi TTE tersertifikasi
BSrE berada di luar lingkup skripsi ini.

## Alternatif yang ditolak

- **Kode QR di-hash seperti Nota Dinas.** Membuat BAST tidak bisa dirender
  ulang dengan QR yang sama (lihat analisis di atas).
- **Menyimpan PDF bertanda tangan sebagai berkas.** Tidak deterministik
  (dompdf menanam waktu pembuatan) dan menggandakan sumber kebenaran.
  Snapshot JSON beserta sidiknya cukup, dan PDF-nya selalu bisa dibuat ulang.
- **Render hidup tanpa snapshot.** Mengganti nama barang di master akan
  mengubah dokumen yang sudah ditandatangani, atau membuat sidiknya tidak
  cocok tanpa ada yang salah.
- **Status realisasi baru** (mis. "Proses Serah Terima"). Narendra menempatkan
  TTE di dalam Menunggu Penerimaan, dan kemajuannya sudah terbaca dari data
  BAST. Status baru hanya menambah transisi yang harus dijaga.
- **Kata sandi saat TTE.** Ini rekomendasi Claude; ditolak Narendra demi
  kecepatan di lapangan.
- **Admin boleh TTE.** Pemisahan tugas pada BAST runtuh.
- **Hanya `purchased_by` yang boleh menjadi Pihak Pertama.** BAST macet bila
  orangnya berhalangan.
- **Verifikasi wajib login, atau publik tetapi ringkas.** Pemeriksa luar tidak
  punya akun, dan metadata saja tidak mendeteksi QR yang dipindah ke dokumen
  lain.
- **Melarang perubahan setelah TTE pertama.** Salah ketik nomor menjadi buntu.
  Mencabut lalu meminta tanda tangan ulang mengikuti preseden ADR 0030.
- **Mencatat jumlah draf langsung di `amount_received`.** Laporan, dasbor, dan
  kolom "Diterima" akan menganggap barang sudah masuk stok sebelum TTE.
- **Indeks unik `(receipt, party)`.** Bertabrakan dengan baris TTE yang dicabut
  tetapi disimpan.

## Konsekuensi

- Penerimaan pengadaan kini butuh dua orang menekan tombol. Satu orang tidak
  bisa lagi memasukkan stok pengadaan sendirian; itulah maksudnya.
- Notifikasi, dasbor, dan penyaring daftar memakai satu definisi "menunggu
  TTE".
- **`APP_URL` di server wajib berupa alamat yang bisa dijangkau ponsel
  pemindai.** QR dari data contoh di mesin pengembang menunjuk
  `http://localhost`.
- Nama indeks gabungan bawaan Laravel di tabel baru (65 karakter) melewati batas
  64 MySQL. Indeksnya diberi nama eksplisit, dan SQLite (Pest) tidak menangkap
  hal ini.
- Seeder: realisasi contoh dituntaskan lewat alur TTE sungguhan, ditambah satu
  realisasi yang baru ber-TTE Pihak Pertama. Karena barang `ATK-0xx` tidak ada
  lagi di katalog (butir terbuka 13), keduanya memakai dua barang aktif pertama
  dari katalog bila usulannya kosong.
- Uji pindai QR dengan ponsel sungguhan belum dilakukan.
