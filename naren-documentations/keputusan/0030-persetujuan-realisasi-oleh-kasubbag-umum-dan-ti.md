# ADR 0030 — Persetujuan realisasi pengadaan oleh Kasubbag Umum dan TI

## Status

Diterima — 15 September 2026

## Konteks

Saat presentasi prototipe di lapangan, **Kasubbag Umum dan TI** meminta satu
hal: realisasi pengadaan yang dibuat Pejabat Pengadaan harus dapat ia tinjau
lebih dulu — cukup terima atau tolak — sebelum berjalan.

Sampai [ADR 0029](0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md)
alur pengadaan bertangan tiga: Pengelola Gudang mengusulkan, Pejabat Pengadaan
menyetujui usulan lalu membuat realisasi, Pengelola Gudang menerima barang.
Realisasi berlaku seketika saat dibuat. Tidak ada pihak yang menilai penyedia,
barang, dan harganya sebelum barang dipesan — padahal itulah titik ketika uang
benar-benar keluar. ADR 0029 sendiri sudah mencatat jenjang ini sebagai langkah
berikutnya yang belum dikerjakan.

Permintaan itu memaksa satu keputusan lain lebih dulu. Hari ini `Gate::before`
meloloskan Admin pada seluruh gate, dan di lapangan **Admin akan dipegang staf
TI**. Menaruh wewenang persetujuan tanpa memisahkan peran sama saja dengan
memberi staf TI wewenang menyetujui belanja. Karena itu peran dipecah:
`admin` tetap peran teknis, dan `kasubbag_umum_ti` menjadi peran jabatan.

Peran `kepala_unit` yang sudah ada **tidak dapat dipakai ulang** — lihat
"Alternatif yang ditolak".

`UserRole` menulis ke `users.role`, dan `users` adalah tabel bersama, sehingga
penambahan case ini perlu disepakati dengan pengembang sub sistem permintaan.

## Keputusan

### Peran baru `kasubbag_umum_ti`, dan satu gate baru

`UserRole::KasubbagUmumTi` disisipkan tepat di bawah `Admin` — urutan case pada
enum itu menyatakan hierarki. Tidak ada migrasi: kolom `users.role` hanya
`string` tanpa constraint basis data.

Gate `setujui-pengadaan` menjaga kedua rute keputusan. Gate `akses-pengadaan`
diperluas agar Kasubbag Umum dan TI dapat membaca dokumen yang ia nilai,
termasuk usulan asalnya.

### Admin tetap lolos gate persetujuan

`Gate::before` **tidak** diberi pengecualian. Konsekuensinya diterima dengan
sadar: pemisahan staf TI dari pemberi persetujuan bersifat **organisasional,
bukan ditegakkan sistem**. Admin tetap berfungsi sebagai jalan darurat ketika
Kasubbag Umum dan TI berhalangan, dan konvensi uji "Admin → 200" tetap utuh
tanpa pengecualian di seluruh modul.

### Realisasi lahir sebagai `MenungguPersetujuan`

`ProcurementStatus` bertambah dua case, sehingga seluruhnya menjadi lima:

| Status | Arti |
|---|---|
| `MenungguPersetujuan` | Keadaan kelahiran yang baru. Barang belum boleh dipesan |
| `MenungguPenerimaan` | Disetujui; menunggu konfirmasi barang diterima |
| `Diterima` | Final. Stok sudah bertambah lewat mutasi immutable |
| `Ditolak` | Ditolak, **masih boleh diperbaiki lalu diajukan ulang** |
| `Dibatalkan` | Terminal; usulan asalnya ikut selesai riwayatnya (ADR 0024) |

Dokumennya memang sudah tersimpan saat dinilai — penyedia, barang, dan harganya
justru yang menjadi bahan penilaian — tetapi belum berlaku sampai disetujui.
Pola yang sama dengan `ProcurementRequestStatus::Draf`
([ADR 0027](0027-draf-usulan-pengadaan-otomatis.md)).

Sebagian besar penjagaannya gratis: `ProcurementReceiptController::create()`,
`ProcurementReceiptPoster::pastikanSiap()`, dan keadaan notifikasi
`RealisasiMenungguPenerimaan` sudah berkunci pada `isMenungguPenerimaan()`,
sehingga realisasi yang belum disetujui otomatis tidak dapat diterima dan stok
tidak mungkin bertambah. Ketiganya **tidak diubah sama sekali**.

Transisi usulan `DisetujuiMenungguRealisasi → DisetujuiSelesai` tetap terjadi
saat realisasi **dibuat**, bukan saat disetujui —
[ADR 0028](0028-status-usulan-disetujui-dipecah-dua.md) menyatakan transisi itu
berarti "realisasinya sudah ada, apa pun nasibnya kelak".

### `Ditolak` tidak terminal, dan menyimpan perubahan itulah pengajuan ulangnya

Satu aturan menutup dua lubang sekaligus: **setiap `update()` yang berhasil
mengembalikan realisasi ke `MenungguPersetujuan` dan mengosongkan
`reviewed_at`/`reviewed_by`/`review_note`.**

- Realisasi yang ditolak tidak memerlukan rute "ajukan ulang" tersendiri.
- Persetujuan tidak dapat diakali. Tanpa aturan ini, Pejabat Pengadaan dapat
  memintakan persetujuan atas harga yang wajar lalu mengubah harganya setelah
  disetujui, dan barang tetap masuk.

Akibatnya setiap versi yang dilihat pemutus adalah versi yang sengaja disimpan
seseorang. Ini **melengkapi**, bukan membatalkan,
[ADR 0025](0025-realisasi-dapat-diubah-sebelum-penerimaan.md): salah ketik tetap
diperbaiki di tempat, bukan dengan membatalkan lalu mengulang dari usulan baru.

Jalan keluar bagi penolakan yang tidak akan diperbaiki tetap `Batalkan`, dengan
seluruh konsekuensi ADR 0024 — termasuk matinya usulan asal.

### Alasan penolakan: teks bebas wajib, belum enum baku

Keterangan wajib diisi saat menolak, opsional saat menyetujui. Alasannya berupa
teks bebas, **bukan** enum baku seperti `AlasanPembatalan` (ADR 0024) atau
`AlasanSelisih` ([ADR 0011](0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md)),
karena daftar alasan yang sebenarnya dipakai di lapangan belum pernah
ditanyakan. Enum dapat ditambahkan kelak begitu daftarnya diketahui.

### Agregat: `Ditolak` ikut dikeluarkan

Prinsip [ADR 0026](0026-agregat-tidak-menghitung-realisasi-batal.md) diperluas:
yang dihitung adalah pengadaan yang terjadi, bukan dokumen yang dibuat.
`Ditolak` masuk kategori yang sama dengan `Dibatalkan` —
`ProcurementStatus::takPernahJadiTransaksi()` menamai keduanya. Ditampilkan,
tidak pernah dihitung. Invarian rekap berubah menjadi
`total = menunggu_persetujuan + menunggu + diterima`.

Cacah penolakan pada halaman penyedia berdiri sendiri, tidak dilebur ke dalam
cacah pembatalan: penolakan tidak terminal, sehingga menyebutnya "dibatalkan"
sama menyesatkannya.

### Tampilan: grup menu, bukan layar baru

Kasubbag Umum dan TI mendapat grup menu **Persetujuan** berisi satu tautan ke
`/pengadaan?status=menunggu_persetujuan`. Keputusannya diambil dari halaman
detail realisasi yang sudah ada, lewat tombol Setujui dan modal Tolak — pola
yang sama dengan cara Pejabat Pengadaan memutuskan usulan hari ini.

Grup menu dipilih justru karena murah dan dapat bertumbuh: ketika sub sistem
permintaan barang siap, tautan persetujuannya cukup ditambahkan sebagai item
kedua, tanpa layar baru dan tanpa satu pun data yang perlu dibagi antar sub
sistem.

## Alternatif yang ditolak

**Memakai ulang `UserRole::KepalaUnit`.** Tampak hemat karena peran itu sudah
ada dan sudah menyetujui sesuatu. Ditolak karena semantiknya berbeda:
`KepalaUnit` adalah kepala unit **pemohon**, di-scope per unit pada
`RequestController`, sedangkan Kasubbag Umum dan TI adalah kepala subbagian yang
**menaungi gudang**. Menggabungkannya akan merusak penyaringan unit di sub
sistem permintaan.

**`Ditolak` sebagai status terminal seperti `Dibatalkan`.** Paling sederhana dan
paling dekat dengan permintaan lapangan yang hanya menyebut "terima atau tolak".
Ditolak karena `procurements.procurement_request_id` bersifat UNIQUE: realisasi
yang mati permanen membuat usulan asalnya ikut mati permanen, sehingga penolakan
karena satu harga keliru memaksa gudang mengusulkan ulang dari nol. Itu jalan
buntu yang sama persis dengan yang diperbaiki ADR 0024, dan ADR itu sendiri
memperingatkan bahwa catatan serupa pernah menyembunyikan jalan buntu selama
satu tahap penuh.

**Mengecualikan Admin dari `Gate::before` untuk `setujui-pengadaan`.** Secara
tata kelola lebih rapat: staf TI tidak akan pernah bisa menyetujui belanja.
Ditolak setelah ditimbang bersama pengguna karena menghilangkan jalan darurat
saat pemutusnya berhalangan, dan memecah konvensi uji "Admin → 200" yang
berlaku seragam di seluruh modul. Pemisahannya ditegakkan secara organisasional
— dan keterbatasan itu dicatat di sini alih-alih didiamkan.

**Layar "Kotak Persetujuan" tersendiri di `/persetujuan`.** Lebih meyakinkan saat
demo dan sejak awal dirancang menampung banyak jenis persetujuan. Ditolak karena
menduplikasi daftar, penyaring, dan rekap yang sudah ada, sementara versi lintas
sub sistemnya menuntut layar ini membaca data sub sistem permintaan — kopling
yang selama ini justru dijaga agar tidak ada.

**Enum `AlasanPenolakan` sejak awal.** Konsisten dengan ADR 0011 dan ADR 0024.
Ditolak untuk sekarang karena daftar alasannya akan jadi tebakan, dan enum yang
salah justru memaksa pemutus memilih alasan yang tidak tepat.

**Penagih `RealisasiBelumDisetujui` berambang hari**, seperti
`UsulanBelumDirealisasi`. Tidak diperlukan: notifikasi keadaan
`RealisasiMenungguPersetujuan` sudah menagih terus-menerus selama dokumennya
belum diputuskan.

## Konsekuensi

- `app/Enums/UserRole.php`: case `KasubbagUmumTi`. Ketiga `match` di dalamnya
  ekshaustif tanpa arm `default`, jadi ketiganya wajib ikut diperbarui.
- `app/Providers/AppServiceProvider.php`: gate `setujui-pengadaan` baru,
  `akses-pengadaan` diperluas ke peran baru. Efek sampingnya, wewenang unggah
  berkas [ADR 0022](0022-wewenang-unggah-dan-hapus-berkas-pengadaan.md) ikut
  mencakup Kasubbag Umum dan TI — dinilai wajar karena ia atasan langsung di
  subbagian yang sama.
- `app/Enums/ProcurementStatus.php`: dua case baru, helper `masihBerjalan()` dan
  `takPernahJadiTransaksi()`. Docblock lamanya ditulis ulang — kalimat "alur ini
  tidak mengulang tahap persetujuan" persis yang dibatalkan ADR ini.
- **Migrasi** `2026_09_15_000001_add_approval_to_procurements_table.php`:
  `reviewed_at`, `reviewed_by`, `review_note`, seluruhnya nullable. Satu set
  kolom menampung keputusan terakhir — persetujuan maupun penolakan — karena
  statusnya yang membedakan; riwayat lengkapnya tetap di `audit_logs` yang
  immutable. `procurements` sepenuhnya milik Naren, tidak perlu kesepakatan.
- `app/Http/Controllers/Pengadaan/ProcurementApprovalController.php` baru, plus
  `ApproveProcurementRealizationRequest` dan
  `RejectProcurementRealizationRequest`. Namanya memakai `...Realization...`
  karena `ApproveProcurementRequestRequest` sudah dipakai persetujuan usulan.
- `routes/web.php`: grup `can:setujui-pengadaan` berisi dua rute PATCH. Keduanya
  PATCH sehingga bebas dari jebakan urutan rute yang mengenai GET segmen statis
  versus GET wildcard.
- `ProcurementController`: status kelahiran berubah; `edit()`, `update()`, dan
  `cancel()` berpindah dari `isMenungguPenerimaan()` ke `masihBerjalan()`;
  `update()` menyetel ulang status dan jejak review; `summary()` memakai invarian
  baru.
- `app/Models/User.php`: relasi `reviewedProcurements()` **dan** pendaftarannya
  di `RESTRICTED_RELATIONS`. FK-nya `restrictOnDelete()`, jadi tanpa itu
  penghapusan akun peninjau meledak jadi 500 alih-alih pesan validasi
  ([ADR 0003](0003-arsip-alih-alih-hapus.md)).
- Tiga tipe notifikasi baru: `RealisasiMenungguPersetujuan` (keadaan → Kasubbag
  Umum dan TI + Admin), `RealisasiDisetujui` dan `RealisasiDitolak` (peristiwa →
  perealisasi). `NotificationType::level()` tidak punya arm `default`, sehingga
  case yang terlewat langsung `UnhandledMatchError`.
- `NotificationScanner::periksaRealisasi()` menerima koleksi penerima kedua
  karena antrean persetujuan ditagih ke jabatan yang berbeda dari penerimaan.
- Seeder: akun `kasubbag`, satu usulan tambahan, dan satu realisasi contoh yang
  berhenti di `MenungguPersetujuan` supaya layar pemutus tidak kosong pada data
  awal.
- 27 pengujian baru; seluruh suite 365 lolos.
- **Perlu kesepakatan dengan pengembang sub sistem permintaan** karena `users`
  tabel bersama. Tawarannya dua arah: transisi final permintaan
  (`processed_by_staff → completed`) saat ini di-*hardcode* ke Admin pada
  `RequestController`, padahal secara jabatan itu Kasubbag Umum dan TI. Apakah
  peran ini dipakai di sana dan kapan, sepenuhnya keputusan pengembangnya —
  ADR ini tidak menyentuh satu pun berkas sub sistem permintaan.

### ADR yang diperluas, bukan digantikan

ADR ini memperluas cakupan 0016, 0021, 0022, 0025, 0026, dan 0028 **tanpa
menggantikan satu pun**: tidak ada keputusan lama yang dibalik, hanya
cakupannya yang bertambah. Karena itu tidak ada ADR yang berubah statusnya
menjadi `Digantikan oleh 0030`. Penalaran ini ditulis eksplisit supaya
pembacaannya tidak menjadi tebakan di kemudian hari.

### Langkah berikutnya yang belum dikerjakan

- Realisasi yang dibuat langsung oleh Pejabat Pengadaan tanpa usulan — masih
  tertunda sejak ADR 0029.
- Persetujuan Kasubbag Umum dan TI pada sub sistem permintaan barang, bila
  pengembangnya memutuskan memakai peran ini.
- Enum alasan penolakan, begitu daftar alasan lapangan diketahui.
