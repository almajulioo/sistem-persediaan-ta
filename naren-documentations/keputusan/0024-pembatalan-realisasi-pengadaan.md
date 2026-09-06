# ADR 0024 — Pembatalan realisasi pengadaan, dan penagih usulan yang menganggur

## Status

Diterima — 7 September 2026

## Konteks

Modul Pengadaan selesai empat tahap pada 6 September 2026. Pemeriksaan
menyeluruh sehari sesudahnya menemukan bahwa yang belum beres bukan kode yang
baru ditulis, melainkan **alurnya sendiri**.

### Jalan buntu

`ProcurementStatus::Dibatalkan` sudah ada di enum sejak Tahap 1, tetapi tidak
pernah punya pemicu, dan `modul/pengadaan.md` mencatatnya sebagai "belum ada
kebutuhan lapangan yang mengonfirmasinya". Ternyata kebutuhannya sudah tersirat
di [ADR 0016](0016-alur-pengadaan-tiga-dokumen.md) sendiri: pembelian barangnya
terjadi **di luar sistem**, di antara realisasi dan penerimaan. Ruang di luar
itulah tempat penyedia gagal kirim, menarik diri, atau kebutuhannya berubah —
kejadian yang wajar, bukan luar biasa.

Ketika itu terjadi, realisasinya terkunci `MenungguPenerimaan` **selamanya**.
Tidak ada rute batal, ubah, maupun hapus. Notifikasi keadaan
`RealisasiMenungguPenerimaan` pun aktif selamanya, karena syaratnya
(`isMenungguPenerimaan()`) tidak akan pernah berubah dengan sendirinya.

Satu-satunya jalan keluar yang tersedia adalah mengonfirmasi penerimaan barang
yang tidak pernah datang — persis kebohongan yang dicegah
[ADR 0017](0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md). Sistem yang
memaksa staf berbohong untuk membersihkan layarnya sudah gagal, seberapa pun
rapi aturan lainnya.

### Titik buta

`NotificationScanner::periksaUsulan()` hanya bekerja selama usulan
`isDiajukan()`. Begitu disetujui, usulan itu hilang dari radar sampai
realisasinya dibuat.

Padahal seluruh perpindahan tangan lain sudah punya penjaga: 1&rarr;2 dijaga
`UsulanMenungguPersetujuan` dan `UsulanMenggantung`, 2&rarr;3 dijaga
`RealisasiMenungguPenerimaan`, 3&rarr;berkas dijaga `BastBelumDiunggah`. Jarak
antara "disetujui" dan "direalisasi" — satu-satunya yang sunyi — justru tempat
barang yang sudah disetujui dapat terlupakan tanpa jejak apa pun.

## Keputusan

### Realisasi dapat dibatalkan, hanya sebelum penerimaan

Rute `PATCH pengadaan/{procurement}/batal` di bawah gate `kelola-pengadaan`.
Pejabat Pengadaan membatalkan dokumen yang dibuatnya sendiri, sejalan dengan
logika "siapa yang memutuskan" pada ADR 0016; Admin lolos lewat `Gate::before`.

Batasnya tegas: **hanya dari `MenungguPenerimaan`**. Begitu barang diterima,
stoknya sudah bertambah lewat mutasi yang immutable
([ADR 0005](0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)) dan tidak ada
lagi yang boleh dibatalkan. Konsekuensi baiknya: **tidak ada satu pun mutasi
stok yang perlu dibalik**, sehingga `StockLedger` dan `ProcurementReceiptPoster`
tidak tersentuh sama sekali oleh fitur ini. Itulah yang membuatnya aman.

### Alasan baku berpasangan keterangan wajib

`procurements.cancel_reason` menyimpan `AlasanPembatalan`
(`PenyediaGagalKirim`, `PenyediaMembatalkan`, `KebutuhanBerubah`,
`KesalahanPencatatan`, `Lainnya`), dan `cancel_note` **wajib diisi** apa pun
alasan yang dipilih — pola yang sama dengan alasan selisih opname
([ADR 0011](0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md)). Enum
membuat pembatalan dapat direkap; hanya keterangannya yang dapat menjawab
pertanyaan yang sebenarnya diajukan pemeriksa.

`cancelled_at` dan `cancelled_by` melengkapinya, sejajar `received_by` pada
penerimaan. Dokumennya sendiri **tidak dihapus**: realisasi yang batal tetap
tersimpan sebagai bukti bahwa pengadaan itu pernah berjalan.

`KesalahanPencatatan` sekaligus menjadi jalan keluar bagi realisasi yang salah
ketik — salah penyedia, salah harga, salah jumlah — yang selama ini tidak punya
jalur koreksi apa pun.

### Pembatalan bersifat terminal bagi usulannya

Indeks unik `procurements.procurement_request_id` **dipertahankan apa adanya**.
Usulan yang realisasinya dibatalkan tidak dapat direalisasikan ulang; bila
barangnya masih dibutuhkan, Pengelola Gudang mengajukan usulan baru.

Dua alasannya. Pertama, invarian ADR 0016 (satu usulan menghasilkan paling
banyak satu realisasi) tetap dijamin **basis data**, bukan kode. Kedua, usulan
baru sekaligus menguji ulang apakah kebutuhannya memang masih ada — harga dan
kebutuhan bisa sudah berubah sejak persetujuan diberikan, dan pengadaan yang
gagal adalah saat yang tepat untuk menanyakannya lagi.

Konsekuensinya harus terlihat di layar: halaman usulan yang realisasinya
dibatalkan menjelaskan mengapa tombol **Realisasikan** tidak ada. Tanpa itu,
usulan berstatus `Disetujui` tanpa tombol terbaca seperti bug.

### Dua notifikasi baru

- **`RealisasiDibatalkan`** (peristiwa, ke Pengelola Gudang + Admin, kecuali
  pelaku). Keadaan `RealisasiMenungguPenerimaan` memang menutup dirinya sendiri
  begitu status berubah — tetapi menghilang diam-diam saja tidak cukup. Gudang
  sudah diberi tahu untuk menunggu barang; yang perlu diketahuinya bukan "tidak
  ada lagi yang ditunggu", melainkan bahwa barangnya tidak jadi datang, dan
  mengapa. Isinya karena itu memuat alasan beserta keterangannya.
- **`UsulanBelumDirealisasi`** (keadaan, ke Pejabat Pengadaan + Admin, ambang
  **7 hari** disamakan dengan `UsulanMenggantung`). Diperiksa di dalam
  `periksaUsulan()` yang sudah ada, bukan sebagai method baru, supaya ikut
  terpicu jalur reaktif maupun sapuan terjadwal. Ambangnya dihitung dari
  `updated_at`: satu-satunya hal yang mengubah baris usulan setelah dibuat
  adalah keputusan setujui/tolak itu sendiri.

  Usulan yang realisasinya **dibatalkan tidak ikut ditagih** — menurut
  keputusan di atas riwayat usulan itu memang sudah selesai, dan menagihnya
  berarti menyuruh orang melakukan sesuatu yang tidak mungkin lagi.

## Alternatif yang ditolak

**Menghapus baris realisasinya.** Paling sederhana, dan tabelnya tetap bersih.
Ditolak karena realisasi bukan draf: ia sudah menjadi komunikasi kepada
penyedia, sudah memuat harga yang disepakati, dan sudah memicu notifikasi ke
gudang untuk menunggu barang. Membuangnya menghapus bukti bahwa semua itu
pernah terjadi. Bandingkan dengan draf opname yang memang boleh dihapus
([ADR 0010](0010-alur-stok-opname-dan-penyesuaian-selisih.md)) — draf itu belum
pernah keluar dari meja pembuatnya.

**Melepas indeks unik supaya usulannya dapat direalisasikan ulang ke penyedia
lain.** Persetujuan yang sudah diberikan tidak terbuang, dan Pejabat Pengadaan
tinggal merealisasi ulang. Ditolak karena jaminan "satu usulan, satu realisasi"
pindah dari basis data ke kode — MySQL tidak mendukung indeks unik parsial,
sehingga syaratnya harus menjadi "paling banyak satu realisasi yang belum
dibatalkan" yang hanya ditegakkan form request. Satu usulan pun berubah
memetakan ke 0..n realisasi sepanjang waktu, melemahkan model yang dibangun
ADR 0016.

**Membiarkan gudang mengonfirmasi penerimaan nol sebagai cara membatalkan.**
Tidak perlu skema, rute, maupun enum baru sama sekali. Ditolak karena mencatat
"barang diterima sebanyak 0" padahal yang terjadi adalah **tidak ada serah
terima sama sekali** — dan lebih buruk, itu menerbitkan BAST bernomor atas
peristiwa yang tidak pernah ada. Dokumen serah terima yang menyatakan serah
terima nol adalah dokumen yang berbohong dengan cara yang lebih halus.

**Membiarkan realisasi menggantung dan hanya menambah notifikasi
"realisasi terlalu lama menunggu".** Menagih tanpa mengubah skema apa pun.
Ditolak karena menagih sesuatu yang tidak punya penyelesaian: penerimanya
diingatkan berulang kali tentang keadaan yang memang tidak dapat diperbaikinya.
Notifikasi yang tidak dapat ditindaklanjuti melanggar ujian kedua katalog
notifikasi — "ada yang bisa dilakukan" — dan hanya melatih orang mengabaikan
lonceng.

**Membatalkan juga diizinkan setelah barang diterima, dengan membalik mutasinya
lewat `KoreksiPencatatan`.** Menjadikan pembatalan berlaku di semua keadaan.
Ditolak karena mencampur dua hal yang berbeda: pengadaan yang gagal terjadi,
dan barang yang sudah masuk lalu ternyata keliru dicatat. Yang kedua sudah
punya jalurnya sendiri — mutasi lawan bertipe `KoreksiPencatatan`
([ADR 0006](0006-mutationtype-merangkap-alasan-manual.md)) — dan menempelkannya
ke pembatalan hanya membuat satu tombol memikul dua makna.

## Konsekuensi

- Realisasi yang dibatalkan tetap muncul di daftar dan laporan dengan status
  `Dibatalkan`. Nilai dipesannya ikut terhitung di rekap "Nilai Dipesan"
  sedangkan nilai diterimanya nol, sehingga catatan kaki laporan pengadaan
  kini menyebut tiga sebab selisih kedua angka itu: kiriman kurang, penerimaan
  yang masih menunggu, dan pembatalan.
- `ProcurementController::store()` kini ikut memanggil `periksaUsulan()`,
  supaya tagihan `UsulanBelumDirealisasi` tertutup seketika ketika usulannya
  benar-benar ditindaklanjuti — bukan menunggu sapuan terjadwal berikutnya.
- `cancelled_by` memakai `restrictOnDelete`, sehingga akun yang pernah
  membatalkan tidak dapat dihapus — sejalan dengan
  [ADR 0003](0003-arsip-alih-alih-hapus.md).
- Butir "belum dikerjakan: `ProcurementStatus::Dibatalkan` masih tanpa pemicu"
  pada `modul/pengadaan.md` selesai.
- Masih tersisa satu keadaan tanpa jalan keluar yang setara: usulan berstatus
  `Disetujui` yang barangnya sudah tidak dibutuhkan tidak dapat ditutup, hanya
  ditagih terus oleh `UsulanBelumDirealisasi`. Belum diputuskan karena belum
  ada kebutuhan lapangan yang mengonfirmasinya — dan kali ini catatan itu
  ditulis dengan kesadaran bahwa catatan serupa untuk `Dibatalkan` ternyata
  menyembunyikan jalan buntu selama satu tahap penuh.
