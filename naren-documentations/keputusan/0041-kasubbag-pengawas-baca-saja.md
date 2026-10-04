# ADR 0041 — Kasubbag Umum dan TI sebagai pengawas baca-saja gudang dan pengadaan

## Status

Diterima — 5 Oktober 2026

Menggantikan sebagian [ADR 0022](0022-wewenang-unggah-dan-hapus-berkas-pengadaan.md):
gate untuk **mengunggah** berkas pengadaan. Penghapusan berkas tidak berubah.

## Konteks

Sejak [ADR 0030](0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md),
Kasubbag Umum dan TI hanya bisa membuka halaman pengadaan (`akses-pengadaan`)
dan menyetujui atau menolak realisasi. Halaman gudang — master barang, riwayat
mutasi, stok opname — dan penyedia tertutup baginya, karena rute baca dan
tulisnya berada dalam satu grup `kelola-gudang` / `kelola-pengadaan`.

Pengguna meminta Kasubbag diperlakukan "seperti Admin, tetapi tanpa menu
teknis Admin" (Manajemen Pengguna, Log Aktivitas), dengan gudang dan
pengadaan **baca-saja**, dan tetap berfungsi sebagai penyetuju realisasi.

Penelusuran sebelum keputusan ini menemukan celah: rute unggah berkas
pengadaan dijaga `akses-pengadaan`, yang sejak ADR 0030 ikut meloloskan
Kasubbag. ADR 0022 ditulis saat gate itu baru berisi dua peran pelaksana dan
tidak pernah ditinjau ulang, sehingga **Kasubbag bisa melampirkan BAST,
SPK, dan foto belanja ke realisasi yang ia nilai sendiri.**

## Keputusan

**1. Kasubbag membaca dan mencetak seluruh data gudang dan pengadaan
subbagiannya, dan tidak mengubah apa pun selain keputusan persetujuan.**

Alasannya:

- **Mutu keputusan persetujuan.** Sebelum menyetujui realisasi, Kasubbag dapat
  memeriksa sendiri stok barangnya, riwayat pemakaian lewat kartu persediaan,
  dan pengadaan sebelumnya dari penyedia yang sama — bukan hanya membaca
  dokumen yang diajukan kepadanya.
- **Pengawasan atasan langsung.** Kasubbag Umum dan TI adalah atasan Pengelola
  Gudang dan Pejabat Pengadaan; melihat seluruh proses subbagiannya adalah
  bagian dari pengendalian intern.
- **Baca-saja menjaga pemisahan tugas ([ADR 0016](0016-alur-pengadaan-tiga-dokumen.md)).**
  Penyetuju yang bisa mengubah data yang ia setujui, atau membuat dokumen yang
  lalu ia setujui sendiri, meruntuhkan alur tiga dokumen.

**2. Tiga gate baru, pola yang sama dengan `akses-pengadaan`** (gabungan
peran, karena `can:` yang ditumpuk bersifat DAN — ADR 0002):

| Gate | Lolos | Dipakai untuk |
|---|---|---|
| `akses-gudang` | Pengelola Gudang, Kasubbag (+ Admin) | daftar & rincian barang, kartu persediaan, riwayat mutasi + cetak, daftar & rincian opname + cetak |
| `akses-penyedia` | Pejabat Pengadaan, Kasubbag (+ Admin) | daftar & rincian penyedia |
| `unggah-berkas-pengadaan` | Pejabat Pengadaan, Pengelola Gudang (+ Admin) | unggah berkas pengadaan |

Gate `kelola-*` tidak berubah, dan setiap rute yang mengubah data tetap di
grupnya. Rute baca dipindah ke grup baru yang didaftarkan **sesudah** grup
tulis, supaya `…/create` tidak tertelan rute berparameter.

**3. Mengunggah berkas pengadaan hanya bagi pelaksana.** Rute unggah memakai
`can:unggah-berkas-pengadaan` di atas `akses-pengadaan`, dan form request-nya
memeriksa gate yang sama. Pertimbangan ADR 0022 tetap berlaku — melampirkan
bukti bukan memutuskan — tetapi bagi **penilai** dokumen itu sendiri,
menambahkan bukti ke realisasi yang ia nilai adalah konflik kepentingan.

**4. Tampilan mengikuti hak, tanpa penanda "baca-saja".** Tombol tambah, ubah,
arsipkan, hapus, catat mutasi, mulai/lanjut cacah, dan formulir unggah
dibungkus `@can('kelola-*')` / `@can('unggah-berkas-pengadaan')`; kolom Aksi
hilang seluruhnya bila kosong. Tidak ada spanduk penjelas (pedoman UI bersih).
Tombol cetak dan ekspor tetap ada.

**5. Menu samping.** Grup Pengadaan dan Manajemen Gudang terbuka bagi Kasubbag.
Tiga sub-item pengadaan di grup Manajemen Gudang kini khusus Pengelola Gudang,
sehingga Admin dan Kasubbag tidak lagi melihat "Usulan Pengadaan", "Realisasi
Pengadaan", dan "Usulan Barang Baru" dua kali.

**6. Dasbor mengikuti rute** ([ADR 0040](0040-dasbor-berbasis-peran-dari-data-langsung.md)):
ubin dan tautan gudang memakai `akses-gudang`, ubin penyedia `akses-penyedia`.
Antrean "Perlu Ditindaklanjuti" Kasubbag tetap hanya persetujuan — pekerjaan
pelaksana bukan antreannya; kemacetan proses terlihat di Alur Pengadaan.

## Alternatif yang ditolak

**Kasubbag dimasukkan ke gate `kelola-gudang` / `kelola-pengadaan`.** Paling
sedikit perubahan, tetapi memberinya hak tulis — persis yang harus dicegah
ADR 0016.

**Middleware "salah satu gate" buatan sendiri** (`can_any:kelola-gudang,…`).
Pola baru untuk satu kebutuhan, padahal gate gabungan sudah menjadi pola
proyek ini sejak `akses-pengadaan`.

**Notifikasi gudang (stok menipis, opname, BAST) juga dikirim ke Kasubbag.**
Notifikasi untuk yang harus bertindak; bagi pengawas itu kebisingan harian.
Gambaran umumnya sudah ada di dasbor.

**Log Aktivitas dibuka bagi Kasubbag sebagai alat pengawasan.** Tidak diminta,
dan kolomnya teknis (alamat IP, peran pelaku). Siapa yang mengusulkan,
merealisasi, menyetujui, menerima, dan mengunggah sudah tercatat di tiap
dokumen.

## Konsekuensi

- Kasubbag tidak lagi dapat mengunggah berkas. Berkas yang mungkin sudah
  terlanjur ia unggah tetap boleh ia hapus (pengunggahnya sendiri, ADR 0022).
- Halaman baca gudang dan penyedia kini butuh `@can` untuk setiap tombol tulis
  — halaman baru di modul ini wajib mengikuti, karena tidak lagi tertutup
  seluruhnya bagi peran non-pelaksana.
- Rute permintaan barang (sub sistem Alma) dan enum `UserRole` tidak
  disentuh.
