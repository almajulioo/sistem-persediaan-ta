# ADR 0028 — Status usulan "Disetujui" dipecah menjadi dua

## Status

Diterima — 13 September 2026

## Konteks

Sebelum keputusan ini, `ProcurementRequestStatus::Disetujui` adalah satu
status tunggal yang menaungi dua keadaan riil yang berbeda jauh: usulan yang
baru disetujui dan belum ditindaklanjuti sama sekali, dan usulan yang sudah
punya realisasi (`Procurement`) — apa pun tahap realisasi itu, termasuk yang
sudah dibatalkan. Kedua keadaan itu sebenarnya **sudah dibedakan** di halaman
detail usulan (`show.blade.php`), lewat pengecekan
`isDisetujui() && ! procurement()->exists()` untuk menampilkan tombol
"Realisasikan", dan `procurement()->exists()` untuk tautan "Lihat Realisasi"
— tetapi hanya di situ. Tabel daftar usulan dan filter status tidak
membedakannya sama sekali; keduanya sama-sama tampil sebagai "Disetujui".

Diminta agar perbedaan itu terlihat jelas di tabel dan **bisa disaring**
lewat filter status, bukan cuma tersirat dari relasi yang harus dibuka satu
per satu.

## Keputusan

### Dua status tersimpan, bukan status turunan

`ProcurementRequestStatus::Disetujui` dipecah menjadi dua case sungguhan,
tersimpan di kolom `status` seperti status lain di enum ini (bukan dihitung
saat dibaca, berbeda dari `Item::stockStatus()`):

- `DisetujuiMenungguRealisasi` — belum ada `Procurement` untuk usulan ini.
- `DisetujuiSelesai` — sudah ada `Procurement` untuk usulan ini, **apa pun
  nasibnya**, termasuk yang sudah dibatalkan. "Selesai" di sini murni berarti
  "sudah dibuatkan realisasi", bukan "barangnya sudah diterima" — definisi
  yang sengaja dipilih supaya kategorinya biner dan tidak butuh sub-kasus
  ketiga untuk realisasi yang batal.

Dipilih sebagai status tersimpan (bukan `scope` turunan seperti
`Item::scopeStockStatus()`) karena permintaannya eksplisit: bisa **disaring**
lewat dropdown filter. Status turunan tetap bisa disaring lewat query, tetapi
filter dropdown-nya harus memakai nilai semu yang tidak berasal dari
`ProcurementRequestStatus::options()`, sementara status tersimpan mendapatkan
filter itu cuma-cuma — `scopeFilter()` yang sudah ada tidak perlu berubah
sama sekali.

### Transisi satu arah, satu titik pemicu

`DisetujuiMenungguRealisasi` diisi `ProcurementRequestController::approve()`
saat Pejabat Pengadaan menyetujui usulan. Transisi ke `DisetujuiSelesai`
terjadi **tepat satu kali**, di `ProcurementController::store()` — satu-
satunya tempat baris `procurements` pernah dibuat dari sebuah usulan (dijaga
`procurement_request_id` UNIQUE) — dalam transaksi yang sama dengan
pembuatan `Procurement`-nya. Transisi ini **tidak pernah berbalik**: sesuai
definisi "selesai" di atas, membatalkan realisasinya
([ADR 0024](0024-pembatalan-realisasi-pengadaan.md)) tidak mengembalikan
status usulan ke `DisetujuiMenungguRealisasi`.

Karena hanya satu titik pemicu, risiko status tersimpan menyimpang dari
kenyataan relasi `procurement` sangat kecil — jauh lebih kecil daripada
risiko serupa pada notifikasi (ADR 0014) yang punya banyak titik panggil
tersebar. Satu pengecualian: `ProcurementSeeder` membuat baris `Procurement`
langsung lewat model, bukan lewat controller, sehingga transisi status
usulannya harus ditiru manual di seeder itu juga — dicatat eksplisit di
komentarnya supaya tidak terlewat oleh sesi berikutnya.

### Efek samping yang menyederhanakan, bukan cuma menambah kerumitan

Karena kedua sub-status sekarang tersimpan langsung, beberapa pengecekan
gabungan yang sebelumnya butuh dua kondisi (`isDisetujui() &&`/`||`
`procurement()->exists()`) di
`ProcurementController::create()`, `StoreProcurementRequest` (form request
realisasi), dan `show.blade.php` menyusut jadi satu kondisi status saja
(`isDisetujuiMenungguRealisasi()`/`isDisetujuiSelesai()`), tanpa perlu
memuat relasi `procurement` untuk sekadar mengecek keberadaannya.
`NotificationScanner::periksaUsulanBelumDirealisasi()` demikian pula —
predikat `isDisetujui() && procurement === null` menyusut jadi cukup
`isDisetujuiMenungguRealisasi()`, dan eager-load `procurement` pada
`periksaSeluruhUsulan()` yang menyertainya jadi tidak diperlukan lagi.

Tempat yang cuma peduli "apakah keputusannya positif", tanpa peduli
sub-detailnya (`NotificationScanner::catatUsulanDiputuskan()`), memakai
helper baru `ProcurementRequest::sudahDisetujui()` yang mencakup keduanya —
supaya niatnya eksplisit di kode, bukan diam-diam bergantung pada urutan
`match` di enum.

### Migrasi data, bukan migrasi skema

Kolom `procurement_requests.status` tetap `string` polos seperti sebelumnya
— tidak ada constraint level basis data yang berubah. Yang berubah hanyalah
**isi** baris lama berstatus `disetujui`: satu migrasi data membackfill-nya
menjadi `disetujui_selesai` bila usulan itu sudah punya baris di
`procurements`, atau `disetujui_menunggu_realisasi` bila belum — persis
aturan yang sama dengan yang dipakai kode aplikasi sejak saat ini.

### Label tampilan lebih pendek daripada nama case

Badge di tabel memakai label "Menunggu Realisasi" dan "Selesai" — tanpa kata
"Disetujui" berulang di setiap badge — karena kedua badge itu sendiri sudah
menyatakan "usulan ini disetujui"; menuliskannya dua kali hanya menambah
kebisingan visual. Nama case di kode tetap deskriptif penuh
(`DisetujuiMenungguRealisasi`/`DisetujuiSelesai`) supaya jelas keduanya
adalah pecahan dari "disetujui", bukan status yang berdiri sendiri.

## Alternatif yang ditolak

**Status turunan (computed) lewat query scope**, sejalan pola
`Item::scopeStockStatus()` — tanpa migrasi data, tanpa risiko drift sama
sekali karena tidak ada nilai tersimpan untuk menyimpang dari kenyataan.
Ditolak karena permintaannya eksplisit menyebut filter dropdown, dan status
turunan memaksa filter itu memakai nilai semu yang terpisah dari
`ProcurementRequestStatus::options()` — menambah percabangan di controller
index untuk menerjemahkan nilai semu itu ke query yang tepat, sesuatu yang
tidak diperlukan sama sekali bila statusnya memang tersimpan.

**Tiga kategori** (menunggu realisasi / direalisasikan / dibatalkan)
alih-alih dua. Lebih presisi secara teknis — realisasi yang batal memang
berbeda nasib dari yang masih berjalan atau sudah diterima. Ditolak atas
permintaan eksplisit: "selesai" didefinisikan murni sebagai "sudah dibuat
realisasinya", bukan "realisasinya berhasil". Nasib realisasi itu sendiri
sudah punya rumah yang tepat — `ProcurementStatus` pada dokumen
`Procurement`-nya sendiri — sehingga menirunya di status usulan hanya akan
menduplikasi informasi yang sama di dua tempat.

## Konsekuensi

- Migrasi data baru: `2026_09_13_000003_split_disetujui_status_on_procurement_requests_table.php`.
- `database/seeders/ProcurementRequestSeeder.php` dan
  `database/seeders/ProcurementSeeder.php` disesuaikan; yang kedua kini
  menirukan transisi status secara manual karena membuat `Procurement`
  langsung lewat model.
- Kartu ringkasan pada `/usulan-pengadaan` bertambah dari 4 menjadi 5 —
  "Disetujui" yang dulu satu kartu kini dua: "Disetujui, Menunggu Realisasi"
  dan "Disetujui, Selesai".
- `ProcurementRequest::isDisetujui()` dihapus, diganti
  `isDisetujuiMenungguRealisasi()`, `isDisetujuiSelesai()`, dan
  `sudahDisetujui()` (gabungan keduanya).
