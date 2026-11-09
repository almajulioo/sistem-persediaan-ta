# ADR 0053 — Usulan barang baru diputuskan dari rincian permohonan; halaman Usulan Barang Baru dihapus

## Status

Diterima — 9 November 2026

Menggantikan sebagian:

- [ADR 0032](0032-usulan-barang-baru.md): formulir usulan milik Pejabat
  Pengadaan (`item-proposals.create`/`store`) dan layar usulan barang
  (`item-proposals.index`/`show`) dihapus. Inti ADR 0032 tetap berlaku:
  menyetujui berarti mendaftarkan barangnya lewat `items.store`, penolakan
  wajib beralasan, dan barang hasil usulan lahir berstok nol.
- [ADR 0040](0040-dasbor-berbasis-peran-dari-data-langsung.md): ubin akses
  cepat "Usulan Barang Baru" dan baris antrean "Usulan barang baru" dihapus
  dari dasbor.

## Konteks

Narendra meminta: "hapus halaman Usulan Barang Baru, sekarang fungsinya ada di
menu Permohonan, sama saja."

Permohonan adalah fitur Alma (Oktober 2026). Siapa pun boleh memohon barang
baru atau tambah stok, lalu Pengelola Gudang menyetujui atau menolaknya.
Saat disetujui, setiap baris barang baru menjadi satu baris `item_proposals`
(tabel bersama sejak [ADR 0033](0033-usulan-barang-baru-tabel-bersama-dengan-permintaan.md)).

Ada dua tumpang tindih:

1. **Pintu masuk ganda.** Pejabat Pengadaan bisa mengusulkan barang baru dari
   formulir `/usulan-barang/create`, padahal Permohonan menerima hal yang sama
   dari siapa pun, Pejabat Pengadaan termasuk.
2. **Keputusan di dua halaman.** Usulan yang lahir dari permohonan tetap harus
   didaftarkan atau ditolak di `/usulan-barang/{id}`. Pengelola Gudang yang sama
   memutuskan barang yang sama dua kali, di dua halaman berbeda.

Halaman lama ternyata bukan sekadar duplikat: ia masih langkah kedua dari
permohonan barang baru. Karena itu letak tombol daftarkan/tolak ditanyakan
lebih dulu. Narendra memilih **memindahkannya ke rincian permohonan**.

## Keputusan

**1. Halaman dan rutenya dihapus.** Yang dihapus: `item-proposals.index`,
`.create`, `.store`, `.show`, ketiga view di `pages/item-proposals/`,
`StoreItemProposalRequest`, dan entri "Usulan Barang Baru" di grup menu
Pengadaan maupun Manajemen Gudang. Yang tersisa:

| Metode | URL | Nama | Gate |
|---|---|---|---|
| PATCH | `usulan-barang/{itemProposal}/tolak` | `item-proposals.reject` | `kelola-gudang` |
| GET | `barang/create?item_proposal_id=…` | `items.create` | `kelola-gudang` |
| POST | `barang` (dengan `item_proposal_id`) | `items.store` | `kelola-gudang` |

**2. Kolom Tindak Lanjut di rincian permohonan memuat keputusannya.** Untuk
setiap baris barang baru yang sudah menjadi usulan:

- status usulan ("Usulan barang baru · Diajukan/Disetujui/Ditolak");
- "Terdaftar sebagai {kode}" bila sudah didaftarkan, bertaut ke halaman barang
  bagi pemegang `akses-gudang`;
- alasan penolakan bila ditolak. Dulu alasan ini hanya terbaca di halaman
  usulan dan di notifikasi yang terhapus setelah 7 hari;
- bagi `kelola-gudang`, selama usulan masih Diajukan: tombol **Daftarkan**
  (ke formulir barang yang terisi nama dan satuannya) dan **Tolak**.

Tombol Tolak membuka **satu modal untuk semua baris**. Baris yang diklik
dikirim lewat event `buka-tolak-usulan-barang`. Bila validasi gagal, modal
dibuka ulang untuk usulan yang sama lewat input tersembunyi
`item_proposal_id`. Modal ini hanya ada setelah permohonan disetujui, jadi
tidak pernah bersamaan dengan modal tolak permohonan walau keduanya memakai
`decision_note`.

**3. Satu definisi "di mana usulan ini dilihat": `ItemProposal::tautan()`.**
Urutannya: permohonan asal, lalu halaman barang bila sudah terdaftar, lalu
daftar permohonan. Dipakai oleh:

- pengalihan setelah daftar dan tolak;
- pengalihan bila formulir barang menunjuk usulan yang sudah diputuskan;
- tombol Batal di formulir barang dari usulan;
- ketiga notifikasi usulan barang;
- tautan subjek di log aktivitas.

Untuk itu `ItemProposal` mendapat relasi `applicationDetail()` (hasOne ke
`item_application_details.item_proposal_id`). Ini perubahan kode pada model
bersama, **bukan** perubahan skema.

**4. Notifikasi `UsulanBarangDisetujui` kini menaut ke permohonan.** Dulu ke
`items.show`, yang butuh `akses-gudang`. Pengusul, baik Pejabat Pengadaan dulu
maupun pemohon sekarang, tidak punya gate itu, jadi tautannya berujung 403.
Permohonannya sendiri selalu boleh ia buka, dan memuat kode barang yang
terdaftar.

**5. Dasbor kehilangan ubin dan baris antreannya.** Pengingat bagi Pengelola
Gudang tetap ada lewat notifikasi keadaan `UsulanBarangMenungguKeputusan`
(Perhatian, diingatkan ulang setelah 7 hari menurut ADR 0037). Baris antrean
tidak dipertahankan karena ADR 0040 mewajibkan angka yang bisa diklik menaut
ke daftar tersaring dengan angka yang sama. Daftar permohonan tidak punya
penyaring "barang baru belum didaftarkan", dan menambahkannya berarti mengubah
controller Alma.

**6. Formulir realisasi** (buat dan ubah): tautan "Usulkan barang baru" pada
pencarian barang yang kosong menjadi "Ajukan permohonan barang baru", ke
`item-applications.create`.

**7. Data contoh** dibuat lewat permohonan. `ItemProposalSeeder` kini membuat
satu permohonan disetujui (`PMH-…-CNTH`) berisi tiga baris barang baru: satu
Diajukan, satu Disetujui, satu Ditolak. Tanpa permohonan, usulan contoh tidak
punya layar keputusan.

**8. Berkas Alma yang disentuh** (sempit):

- `resources/views/pages/request/applications/show.blade.php`: sel Tindak
  Lanjut, modal tolak usulan, dan satu kalimat di modal setujui yang dulu
  menunjuk "Usulan Barang Baru";
- `resources/views/pages/request/index.blade.php`: tautan "Selesaikan usulan
  barang" ke `item-proposals.index` dibuang, karena rutenya sudah tidak ada.

## Alternatif yang ditolak

- **Rincian usulan tetap, sisanya dihapus.** Perubahan paling kecil, tetapi
  masih menyisakan satu halaman "Usulan Barang Baru" yang dibuka dari tautan.
  Itu yang diminta dihapus.
- **Satu keputusan saja:** menyetujui permohonan langsung berarti mendaftarkan
  barangnya. Mengubah alur Alma cukup dalam, dan kode barang serta batas stok
  tiap barang harus diisi di dalam satu modal persetujuan.
- **Mempertahankan baris antrean dasbor yang menaut ke daftar permohonan tanpa
  penyaring.** Angkanya tidak cocok dengan daftar yang dituju (ADR 0040).
- **Menambah penyaring di daftar permohonan** supaya baris antrean tetap ada.
  Mengubah controller milik Alma untuk sesuatu yang tidak diminta.

## Konsekuensi

- Pejabat Pengadaan tidak lagi mengusulkan barang baru langsung. Ia mengajukan
  permohonan, Pengelola Gudang menyetujuinya, lalu mendaftarkan barangnya dari
  halaman yang sama.
- **Usulan lama tanpa permohonan tidak punya tombol keputusan lagi.** Yang
  termasuk: usulan dari formulir lama Pejabat Pengadaan, dan usulan dari alur
  permintaan lama (`request_details.item_proposal_id`, yang sudah tidak bisa
  dibuat sejak permintaan menolak barang belum terdaftar). Di basis data lokal
  hanya ada data contoh, dan seeder sudah diganti. Bila data lapangan memuat
  usulan Diajukan semacam itu, mendaftarkannya masih bisa lewat
  `/barang/create?item_proposal_id=…`, tetapi menolaknya tidak punya tombol.
- Kalimat "Pastikan barangnya belum terdaftar dengan nama lain sebelum
  menyetujui." dari halaman usulan lama tidak dibawa. Isi notifikasi
  `UsulanBarangMenungguKeputusan` tetap meminta pemeriksaan yang sama.
- Baris notifikasi lama yang masih menyimpan tautan `/usulan-barang/{id}`:
  - keadaan ditulis ulang oleh `notifikasi:periksa` berikutnya
    (`periksaSeluruhUsulanBarang()`);
  - peristiwa (Disetujui/Ditolak) membawa tautan mati sampai terhapus 7 hari
    setelah dibuat.
