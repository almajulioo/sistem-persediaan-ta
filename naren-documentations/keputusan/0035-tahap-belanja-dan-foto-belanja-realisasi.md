# ADR 0035 — Tahap belanja pada realisasi pengadaan, berbukti foto belanja

## Status

Diterima — 23 September 2026

## Konteks

Permintaan langsung **Pejabat Pengadaan** di lapangan. Sejak
[ADR 0030](0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md), begitu
Kasubbag Umum dan TI menyetujui sebuah realisasi, `ProcurementApprovalController::approve()`
langsung memindahkannya ke `MenungguPenerimaan`, dan
`NotificationScanner::periksaRealisasi()` seketika menerbitkan
`RealisasiMenungguPenerimaan` ke Pengelola Gudang.

Padahal di lapangan, persetujuan baru berarti **barang boleh dibeli**. Di
antara persetujuan dan kedatangan barang ada satu kegiatan nyata yang tidak
terlihat di sistem sama sekali: Pejabat Pengadaan pergi berbelanja. Akibatnya
dua:

- Status realisasi tidak dapat menjawab pertanyaan paling sederhana di
  lapangan — *barangnya sudah dibeli atau belum?* "Menunggu Penerimaan" terbaca
  sama, entah belanjanya belum dimulai atau barangnya sudah di jalan.
- Gudang ditagih menunggu barang **yang belum dibeli siapa pun**. Tagihannya
  benar secara teknis, tetapi terbit terlalu dini untuk dapat ditindaklanjuti.

Permintaan kedua dari orang yang sama: realisasi dapat dilampiri **foto barang
atau foto kegiatan belanjanya**, dengan format berkas yang lebih longgar dari
PDF/JPG/PNG yang selama ini diterima. Pertanyaan terbukanya: apakah status
boleh diubah kapan saja dengan foto menyusul — seperti BAST pada
[ADR 0018](0018-berkas-unggahan-di-disk-privat.md) — atau fotonya menjadi
syarat.

## Keputusan

Keempat butir berikut diajukan sebagai rekomendasi dan dipilih pengguna lewat
empat pertanyaan eksplisit sebelum dibangun.

### Satu status baru, bukan dua

Permintaan aslinya menyebut dua status: "menunggu dibelanjakan" dan "sudah
dibelanjakan", baru kemudian "menunggu penerimaan". Yang dibangun hanya
**satu**: `ProcurementStatus::ProsesBelanja` (label *Proses Belanja*).

```
MenungguPersetujuan ──disetujui──▶ ProsesBelanja ──ditandai sudah dibelanjakan──▶ MenungguPenerimaan ──diterima──▶ Diterima
```

"Sudah dibelanjakan" dan "menunggu penerimaan" adalah **keadaan fisik yang sama
persis**: barang sudah dibeli, gudang belum mengonfirmasi. Dua status untuk
satu keadaan berarti ada perpindahan di antara keduanya yang tidak dipicu oleh
siapa pun — tidak ada peristiwa di dunia nyata yang membedakan "sudah
dibelanjakan" dari "menunggu diterima". Status seperti itu mati sejak lahir.

Nama case `MenungguPenerimaan` beserta labelnya tidak diubah. Istilah itu
sengaja sama persis dengan [ADR 0008](0008-penerimaan-barang-oleh-pengelola-gudang.md),
dan justru itulah kalimat yang dapat ditindaklanjuti gudang. Kapan barangnya
dibelanjakan tampil di kartu *Belanja* pada halaman detail.

`ProsesBelanja` masuk `masihBerjalan()`: barang yang sedang atau sudah
dibelanjakan belum menyentuh stok, sehingga dokumennya masih boleh diubah
([ADR 0025](0025-realisasi-dapat-diubah-sebelum-penerimaan.md)) maupun
dibatalkan ([ADR 0024](0024-pembatalan-realisasi-pengadaan.md)). Ia **tidak**
masuk `takPernahJadiTransaksi()`: realisasinya sedang berjalan, jadi ikut
dihitung pada agregat ([ADR 0026](0026-agregat-tidak-menghitung-realisasi-batal.md)).

### Fakta belanja disimpan sebagai kolom, bukan dibaca dari status

Migrasi `2026_09_23_000001_add_purchase_to_procurements_table` menambah tiga
kolom nullable pada `procurements`:

| Kolom | Tipe | Isi |
|---|---|---|
| `purchased_at` | `date` | Hari belanja di lapangan; boleh diisi mundur |
| `purchased_by` | FK `users`, `restrictOnDelete` | Siapa yang menandai — biasanya perealisasi, bisa Admin |
| `purchase_note` | `string(500)` | Catatan opsional, mis. nama toko |

Pola yang sama dengan `reviewed_*` (ADR 0030) dan `cancelled_*` (ADR 0024).
`purchased_at` bertipe tanggal, bukan timestamp: yang dicatat hari belanjanya,
bukan detik tombolnya ditekan — detik itu sudah ada di `audit_logs`.
`User::purchasedProcurements()` langsung didaftarkan ke `RESTRICTED_RELATIONS`
bersama kolomnya, supaya tidak mengulang kekeliruan `cancelledProcurements()`
yang dulu meledak jadi 500 saat akun dihapus.

`Procurement::sudahDibelanjakan()` ditanyakan dari `purchased_at`, **bukan**
dari status. Alasannya ada di butir berikut.

### Mengubah dokumen mengulang penilaian, bukan mengulang belanja

ADR 0030 menetapkan bahwa **setiap** perubahan realisasi mengembalikannya ke
`MenungguPersetujuan`. Aturan itu tetap berlaku, termasuk setelah barang
dibelanjakan — justru di situ ia paling berguna: harga di toko ternyata
berbeda dari yang disetujui, Pejabat Pengadaan mengoreksinya, dan Kasubbag
Umum dan TI menilai ulang dengan harga sebenarnya.

Yang **tidak** ikut digugurkan adalah fakta belanjanya. Belanja yang sudah
terjadi tidak batal karena dokumennya diedit. Karena itu
`ProcurementApprovalController::approve()` memilih status berikutnya:

```php
'status' => $sudahDibelanjakan ? ProcurementStatus::MenungguPenerimaan : ProcurementStatus::ProsesBelanja,
```

Realisasi yang disetujui ulang setelah dibelanjakan langsung kembali menunggu
penerimaan. Menyuruh Pejabat Pengadaan menandai "sudah dibelanjakan" untuk
kedua kalinya atas belanja yang sama sama saja memaksanya berbohong — prinsip
yang sama dengan [ADR 0017](0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md).

### Foto belanja wajib minimal satu, dihitung dari keadaan

Tombol **Tandai Sudah Dibelanjakan** membuka dialog berisi tanggal belanja,
foto (maks. 5 per kiriman), dan catatan. Penandaan ditolak bila realisasi itu
belum punya satu pun foto belanja.

Syaratnya dihitung dari **keadaan**, bukan dari kiriman itu saja: foto
`FotoBelanja` yang sudah diunggah lebih dulu lewat kartu Berkas Pendukung ikut
dihitung (`Procurement::jumlahFotoBelanja()`). Foto tambahan — misalnya foto
barang saat tiba — tetap boleh menyusul kapan pun.

Ini sengaja **berkebalikan** dengan BAST pada ADR 0018, dan bedanya bukan soal
jenis berkas, melainkan kapan berkasnya lahir:

- **BAST** butuh tanda tangan orang lain, lalu dipindai. Menunggunya berarti
  menahan stok di belakang alur tanda tangan, jadi ia dibiarkan menyusul dan
  kelalaiannya ditagih `BastBelumDiunggah`.
- **Foto belanja** dibuat Pejabat Pengadaan sendiri *pada saat* belanja.
  Ketika tombolnya ditekan, fotonya sudah ada di ponselnya. Tidak ada jeda
  yang sah untuk ditunggu.

Mewajibkannya pun tidak menahan stok. Yang tertahan hanya tagihan ke gudang,
dan tagihan itu memang tidak layak terbit sebelum barangnya dibeli.

Foto disimpan di tabel `procurement_documents` yang sudah ada, sebagai jenis
baru `ProcurementDocumentType::FotoBelanja`. Kolom `type` string polos,
sehingga tidak ada migrasi. Seluruh jaminan ADR 0018 dan
[ADR 0022](0022-wewenang-unggah-dan-hapus-berkas-pengadaan.md) ikut berlaku
tanpa satu baris kode baru: disk privat, nama berkas UUID, unduhan terautentikasi
dan tercatat, hapus hanya oleh pengunggah atau Admin.

### Yang menandai adalah Pejabat Pengadaan

Rute `PATCH /pengadaan/{procurement}/dibelanjakan` (`procurements.purchase`)
berada di grup `can:kelola-pengadaan` — Pejabat Pengadaan, dan Admin lewat
`Gate::before`. Yang membelanjakan dialah yang mencatat belanjanya; pemisahan
tugas [ADR 0016](0016-alur-pengadaan-tiga-dokumen.md) tetap tiga orang.

Controller-nya dipisah menjadi `ProcurementPurchaseController`, mengikuti
preseden satu kelas per tahap (`ProcurementApprovalController`,
`ProcurementReceiptController`). Tidak ada rute "batal dibelanjakan": belanja
adalah fakta fisik, kekeliruan diperbaiki lewat ubah realisasi, dan realisasi
yang tidak jadi diterima keluar lewat pembatalan.

Berkas foto ditulis ke disk **sebelum** transaksi basis data, karena disk tidak
ikut transaksi; bila transaksinya gagal, berkas yang sudah tertulis dibuang lagi
lewat `DocumentStorage::hapusBerkas()`.

### Gudang tidak menerima barang yang belum ditandai dibelanjakan

`ProcurementReceiptController::create()` dan `ProcurementReceiptPoster` tetap
hanya menerima dari `MenungguPenerimaan`. Selama `ProsesBelanja`, gudang
mendapat pesan tersendiri (`ProcurementTidakSiapException::belumDibelanjakan()`)
yang menunjuk siapa yang harus bertindak, dan halaman detail menjelaskan kepada
siapa pun mengapa tombol Konfirmasi Penerimaan belum ada.

### Format berkas diperluas, tidak dibebaskan

| Jenis | Format | Ukuran |
|---|---|---|
| Foto Belanja | JPG, PNG, WEBP, HEIC/HEIF | 10 MB |
| BAST, SPK/Kontrak | PDF, JPG, PNG, WEBP, HEIC/HEIF | 10 MB |

Daftarnya satu sumber: `ProcurementDocumentType::formatBerkas()`, dipakai
formulir tandai-belanja maupun unggahan umum. Foto Belanja tidak menerima PDF
di jalur mana pun — PDF yang diberi jenis "foto" akan meloloskan syarat di atas
tanpa satu pun foto. HEIC masuk karena itu format bawaan kamera iPhone. Batas
naik dari 5 ke 10 MB karena foto ponsel lazimnya 3–8 MB.

Validasinya memakai aturan `mimes`, **bukan** `image`: aturan `image` bawaan
Laravel tidak mengenal HEIC.

### Foto dapat dilihat langsung, tetap tercatat

Rute baru `GET .../dokumen/{document}/lihat` (`procurement-documents.show`)
menyajikan berkas *inline* bila `ProcurementDocument::dapatDitampilkan()` —
JPEG, PNG, WEBP, atau PDF, menurut `mime_type` hasil pemeriksaan isi berkas
saat unggah, bukan ekstensi kiriman klien. HEIC jatuh ke unduhan biasa karena
kebanyakan peramban belum dapat membukanya. Tanggapannya membawa
`X-Content-Type-Options: nosniff`.

Melihat dicatat persis seperti mengunduh (`AuditEvent::Unduh`, kalimat
"Melihat …"), sesuai pengecualian [ADR 0034](0034-log-aktivitas-terstruktur.md)
untuk berkas pengadaan: yang dilindungi adalah isinya, dan isi itu sama
terbukanya entah disimpan atau hanya dilihat.

### Notifikasi: tagihan gudang digeser, jarak barunya diberi penagih

- `RealisasiMenungguPenerimaan` kini baru terbit setelah barang ditandai sudah
  dibelanjakan, karena syaratnya (`isMenungguPenerimaan()`) memang baru
  terpenuhi saat itu. Tidak ada kode penagih yang diubah untuk ini. Isinya
  menyebut tanggal belanja, atau "sudah disetujui" bagi realisasi lama yang
  tidak punya tanggal belanja.
- Keadaan baru **`RealisasiBelumDibelanjakan`** menagih Pejabat Pengadaan +
  Admin bila realisasi berada di `ProsesBelanja` lebih dari
  `HARI_REALISASI_BELUM_DIBELANJAKAN` = **7 hari** sejak `reviewed_at`, dan
  menutup dirinya sendiri begitu ditandai, diubah, atau dibatalkan. Tanpa ini
  ADR ini akan membuka jarak sunyi baru. Dulu `RealisasiMenungguPenerimaan`
  menjaga seluruh rentang disetujui&rarr;diterima; kini ia hanya menjaga
  paruh keduanya.
- Penagih itu sengaja **tidak seketika**. Peristiwa `RealisasiDisetujui` sudah
  memberi tahu Pejabat Pengadaan pada saat persetujuan, dan belanja wajar
  memakan beberapa hari. Yang ditagih adalah kelalaian.
- `NotificationScanner::catatRealisasiDiputuskan()` dulu menilai "disetujui"
  dengan `isMenungguPenerimaan()`. Sejak ADR ini persetujuan berujung di dua
  status, sehingga baris itu **wajib** diganti menjadi `! isDitolak()`. Kalau
  tidak, setiap persetujuan akan terkirim sebagai kabar penolakan. Ada uji
  regresinya.

### Jejak audit

Satu baris `Pengadaan / UbahStatus` ("Menandai sudah dibelanjakan: …"), ditambah
satu baris `DokumenPengadaan / Unggah` **per foto**. Baris unggah itu sejajar
dengan unggahan lewat Berkas Pendukung: unduhan dan penghapusan foto itu kelak
tercatat di modul `DokumenPengadaan`, dan tiap baris itu perlu pasangan
unggahnya di modul yang sama. Semuanya ditulis di luar transaksi.

## Alternatif yang ditolak

**Dua status baru persis seperti permintaan awal.** Perpindahan "Sudah
Dibelanjakan" &rarr; "Menunggu Penerimaan" tidak dipicu siapa pun. Pilihannya
hanya membuat klik yang tidak mengubah apa pun secara fisik, atau status yang
tidak pernah ditinggalkan. Varian "satu status baru, lalu label `MenungguPenerimaan`
diganti *Sudah Dibelanjakan*" juga ditawarkan dan tidak dipilih: label itu
menyembunyikan tindakan yang sedang ditunggu dari gudang.

**Foto boleh menyusul, ditagih notifikasi seperti BAST.** Kelonggaran ADR 0018
ada karena BAST punya jeda yang sah. Foto belanja tidak. "Menyusul" untuk
sesuatu yang sudah ada biasanya berakhir menjadi "tidak pernah", dan menjaganya
butuh satu penagih lagi yang tidak perlu ada.

**Foto sepenuhnya opsional.** Sama saja dengan tidak ada bukti. Status "sudah
dibelanjakan" menjadi klaim tanpa dasar, padahal di instansi pemeriksa justru
bukti belanjalah yang akan ditanyakan.

**Gudang boleh menerima langsung dari `ProsesBelanja`, sekaligus mencatat
belanjanya.** Lebih longgar bila Pejabat Pengadaan lupa menandai, tetapi fakta
belanja lalu dicatat oleh orang yang tidak membelanjakan, dengan tanggal
tebakan dan tanpa foto. Risiko lupa ditangani penagih tujuh hari dan penjelasan
di halaman detail.

**Menghapus fakta belanja setiap kali realisasi diubah.** Konsisten dengan
pengosongan `reviewed_*`, tetapi salah sasaran. Penilaian memang harus diulang
atas dokumen yang berubah, sedangkan belanja tidak dapat diulang. Menghapusnya
memaksa Pejabat Pengadaan menandai ulang belanja yang sama.

**Ekstensi berkas dibebaskan sepenuhnya.** Berkas kini dapat ditampilkan
langsung di peramban. SVG maupun HTML yang diunggah akan menjalankan skripnya
di bawah domain aplikasi.

**Gambar kecil (thumbnail) foto di halaman detail.** Setiap tampilan halaman
akan tercatat sebagai pembacaan berkas dan menenggelamkan log aktivitas. Satu-satunya
cara menghindarinya adalah jalur baca yang tidak dicatat, dan itu bertentangan
dengan ADR 0034. Tombol *Lihat* yang membuka tab baru adalah kompromi: satu
klik, satu baris log.

**Tabel atau kolom foto tersendiri.** `procurement_documents` sudah memikul
seluruh jaminan keamanan berkas pengadaan. Tabel baru berarti menulis ulang
semuanya.

**Penagih seketika saat disetujui.** Menduplikasi peristiwa
`RealisasiDisetujui` yang sudah terbit pada detik yang sama.

## Konsekuensi

- Migrasinya murni aditif dan seluruhnya nullable, **tanpa pengisian ulang**.
  Realisasi yang sudah `MenungguPenerimaan` sebelum ADR ini tetap di sana tanpa
  tanggal belanja. Menebak tanggalnya sama dengan mengarang data. Kalimat
  notifikasi dan halaman detail menyesuaikan.
- Tidak ada tabel bersama yang tersentuh: `procurements` dan
  `procurement_documents` sepenuhnya milik sub sistem ini. `users` hanya
  mendapat relasi Eloquent baru, tanpa perubahan skema. `NotificationType`
  mendapat satu case baru, aditif.
- Penerimaan kini bergantung pada satu klik Pejabat Pengadaan. Bila ia lupa,
  gudang tidak dapat menerima barang yang sudah datang. Itu harga pemisahan
  tugasnya, diimbangi penagih tujuh hari dan pesan galat yang menunjuk
  orangnya.
- Rekap daftar realisasi, laporan PDF, dan penyaring status mendapat angka
  *Proses Belanja*. Invarian ADR 0026 menjadi
  `total = menunggu_persetujuan + proses_belanja + menunggu + diterima`.
- Fixture uji berubah makna: `setujuiRealisasi()` kini berujung di
  `ProsesBelanja`. Helper baru `belanjakanRealisasi()` meneruskannya ke
  `MenungguPenerimaan`, dan `buatRealisasiDisetujui()` berganti nama menjadi
  `buatRealisasiSiapDiterima()` supaya namanya jujur.
- **Lingkungan.** PHP di laptop pengembangan memuat `upload_max_filesize=2M`
  dan `post_max_size=8M`, jadi batas 5 MB yang lama pun sebenarnya tidak pernah
  tercapai. Batas 10 MB per foto dan lima foto per kiriman hanya berlaku bila
  php.ini dinaikkan, mis. `upload_max_filesize=12M` dan `post_max_size=64M`,
  di laptop maupun server. Tanpa itu, PHP menolak berkasnya sebelum Laravel
  sempat memvalidasi. Aturan `uploaded` kini diberi pesan yang menyebut
  kemungkinan ini.

### ADR yang diperluas, bukan digantikan

- **ADR 0030** — tujuan persetujuan bergeser dari `MenungguPenerimaan` ke
  `ProsesBelanja` (atau langsung `MenungguPenerimaan` bila sudah dibelanjakan).
  Aturan "setiap perubahan mengulang persetujuan" tetap utuh.
- **ADR 0018 / 0022** — jenis berkas ketiga dan format yang lebih luas. Foto
  belanja wajib, BAST tetap boleh menyusul, dan keduanya punya alasan tertulis.
- **ADR 0013 / 0014 / 0024** — satu keadaan baru menutup jarak yang dibuka ADR
  ini, sehingga seluruh perpindahan tangan pengadaan tetap punya penagih.
- **ADR 0026** — invarian agregat ditambah satu suku.

### Langkah berikutnya yang belum dikerjakan

1. Konfirmasi label **"Proses Belanja"** ke Pejabat Pengadaan pada kunjungan
   lapangan berikutnya. Mengganti label cukup di `ProcurementStatus::label()`.
2. Naikkan batas unggah php.ini di server produksi.
3. Bila foto ponsel ternyata sering melebihi 10 MB, pertimbangkan pengecilan
   di sisi klien sebelum diunggah, bukan menaikkan batasnya terus.
