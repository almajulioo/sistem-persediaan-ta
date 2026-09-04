# ADR 0015 — Usulan pengadaan dan penerimaan barang: skema diperbaiki dan dipisah tegas dari sub sistem Permintaan

## Status

Diterima — 5 September 2026

## Konteks

Sebelum modul Pengadaan mulai dibangun, migrasi warisan ERD-nya (satu commit,
25 Agustus 2026, tidak pernah disentuh lagi) dibaca ulang untuk memastikan
tidak ada yang perlu disepakati dengan Alma lebih dulu — persis seperti
`stock_opnames` dulu perlu penyelarasan sebelum dikembangkan.

Pembacaan literal terhadap migrasi awalnya menyimpulkan ada tiga "perbatasan":
kontrak `procurement_requests.request_id → requests`, kepemilikan
`basts`/`nota_dinas`, dan sumber nomor dokumen. Setelah ditelusuri langsung ke
sumber aslinya, ketiganya ternyata bukan perbatasan sama sekali — melainkan
kesalahan penamaan akibat kurangnya komunikasi saat desain ERD awal:

- `procurement_requests.request_id` **bukan foreign key yang disengaja**.
  Itu sisa kebingungan penamaan primary key. `procurement_request_id` yang
  sekarang menjadi PK sudah benar sejak semula.
- `request_details` **ternyata bukan rincian permintaan unit milik Alma**,
  melainkan rincian usulan pengadaan milik sub sistem ini — salah nama dan
  salah arah FK sejak awal.
- `basts`/`nota_dinas` **sepenuhnya milik Alma**, dokumen sub sistem
  Permintaan. BAST penerimaan barang dari penyedia ke gudang (sisi Pengadaan)
  tidak pernah punya tempat di skema — desain awal cuma bermaksud
  menyimpannya sebagai berkas hasil pindai lewat kolom string bebas
  (`procurements.bast_scan`), dan itu dinilai desain yang kurang baik: tanpa
  nomor dokumen terstruktur, tanpa jejak siapa yang mengonfirmasi.

Sekaligus, narasumber lapangan (Kasubbag Umum dan TI) mengonfirmasi bahwa
usulan pengadaan lahir dari **tiga jalur berbeda**, bukan satu jenis "manual"
generik seperti tersirat dari kolom `request_type` yang tidak pernah
terdokumentasi maknanya:

1. Permintaan unit tidak dapat dipenuhi karena stok kurang atau habis.
2. Pemantauan stok otomatis (notifikasi Stok Habis/Menipis, sudah dibangun di
   modul notifikasi).
3. Diajukan manual oleh Pengelola Gudang.

## Keputusan

**`procurement_requests` diperbaiki, bukan ditinggalkan.** Kolom `request_id`
dan `request_type` dihapus. Ditambahkan:

- `source` (enum `ProcurementRequestSource`: `DariPermintaan`,
  `PemantauanStok`, `Manual`) — menggantikan `request_type`.
- `reference_type`/`reference_id`, polimorfik nullable, **tanpa foreign key
  basis data** — pola yang sama persis dengan `stock_mutations.reference_*`
  ([ADR 0007](0007-kolom-audit-dan-referensi-polimorfik.md)). Diisi hanya
  ketika `source = DariPermintaan`, menunjuk baris `requests` milik Alma lewat
  alias morph map `'permintaan'` yang sudah terdaftar. Dua sumber lainnya
  dibiarkan kosong.
- `status` (enum `ProcurementRequestStatus`: `Diajukan`, `Disetujui`,
  `Ditolak`).

**`request_details` diganti nama menjadi `procurement_request_details`**
(model: `ProcurementRequestDetail`), dan foreign key-nya diperbaiki menunjuk
`procurement_requests`, bukan `requests`.

**`procurements` ditautkan ke usulannya**: kolom `procurement_request_id`
(FK, **wajib diisi dan unik**). Kolom `bast_scan` dihapus. Status memakai
enum terpisah, `ProcurementStatus`: `MenungguPenerimaan`, `Diterima`,
`Dibatalkan` — nama case `MenungguPenerimaan` sengaja memakai istilah persis
yang sudah dipakai [ADR 0008](0008-penerimaan-barang-oleh-pengelola-gudang.md).

**Status dipecah jadi dua enum, satu per tabel**, bukan satu enum lima-tahap
tunggal di `procurements`. `procurement_requests` dan `procurements` adalah
dua dokumen yang benar-benar terpisah — sebuah `Procurement` baru lahir
begitu `ProcurementRequest`-nya `Disetujui`, jadi ia tidak perlu mengulang
tahap persetujuan.

**Tabel baru `procurement_receipts`** menggantikan `bast_scan`: `procurement_id`
(unik), `bast_number`, `file_url`, `received_by` (FK ke `users`, Pengelola
Gudang yang mengonfirmasi), `received_at`, `notes`. `received_by` sengaja
terpisah dari `procurements.user_id` (pengaju/pelaksana realisasi) — inilah
yang memastikan pemisahan tugas pada ADR 0008 benar-benar tercermin di skema,
bukan cuma di alur aplikasi.

## Alternatif yang ditolak

**Mempertahankan `request_id` sebagai foreign key ke `requests`, dijadikan
nullable.** Terlihat masuk akal sebelum ditelusuri ke sumber aslinya — usulan
dari sumber `DariPermintaan` memang perlu menunjuk permintaan tertentu.
Ditolak begitu jelas kolom itu tidak pernah dimaksudkan sebagai relasi sama
sekali; jalur `DariPermintaan` diwadahi lewat `reference_type`/`reference_id`
yang baru, mengikuti pola yang sudah ada di proyek ini, bukan memperbaiki
kolom yang salah sejak awal.

**Satu enum status tunggal di `procurements`, mencakup seluruh lima tahap
(Diajukan → Disetujui → Menunggu Penerimaan → Diterima → Dibatalkan).**
Lebih sederhana — satu kolom, satu enum. Ditolak karena `procurement_requests`
dan `procurements` adalah baris yang berbeda; status "Diajukan" pada sebuah
usulan yang belum disetujui tidak punya arti pada tabel `procurements` karena
barisnya belum ada. Memaksakannya ke satu kolom berarti salah satu tabel
menyimpan status yang tidak pernah benar-benar berlaku untuknya.

**Kolom BAST langsung di `procurements`** (`bast_number`, `bast_file`,
`received_by`, `received_at`) alih-alih tabel terpisah. Lebih sederhana untuk
kasus satu kali penerimaan penuh. Ditolak: tabel terpisah tidak menambah
kerumitan berarti untuk kasus umum, tapi membuka jalan mendukung penerimaan
bertahap (pengiriman parsial dari penyedia) kelak tanpa desain ulang — cukup
melepas constraint `unique` pada `procurement_id`.

**`procurements.procurement_request_id` dibuat nullable**, mengizinkan Pejabat
Pengadaan membuat realisasi langsung tanpa usulan tercatat (misal pembelian
darurat). Ditolak untuk versi ini: setiap pembelian harus bisa ditelusuri
balik ke usulan yang disetujui lebih dulu, sejalan dengan prinsip pemisahan
tugas yang sudah dipegang sistem ini. Kalau kelak ada kebutuhan pembelian
darurat yang terkonfirmasi dari lapangan, kolomnya tinggal dilonggarkan.

## Konsekuensi

- `App\Models\Request::details()` dan `Request::procurementRequests()`
  dihapus — keduanya dibangun di atas premis yang keliru bahwa
  `RequestDetail`/`ProcurementRequest` adalah bagian dari sub sistem
  Permintaan. `requests` milik Alma sekarang **tidak** punya relasi apa pun
  ke tabel manapun di sub sistem ini secara langsung.
- `Item::requestDetails()` diganti nama menjadi `procurementRequestDetails()`,
  dan label pada `Item::RESTRICTED_RELATIONS` diperbarui dari "rincian
  permintaan barang" menjadi "rincian usulan pengadaan" — mencerminkan
  kepemilikan yang benar.
- **Tidak ada lagi yang perlu disepakati dengan Alma** sebelum modul Pengadaan
  dibangun. Kolom `reference_type`/`reference_id` pada
  `procurement_requests` adalah titik integrasi yang tersedia, bukan
  dependensi — sub sistem Pengadaan berfungsi penuh lewat sumber
  `PemantauanStok` dan `Manual` tanpa menunggu sub sistem Permintaan ada.
- `requests` milik Alma **belum punya tabel rincian barangnya sendiri** sama
  sekali. Ini bukan sesuatu yang salah tempat — itu keputusan yang menyusul
  saat sub sistem Permintaan dirancang, di luar cakupan ADR ini.
- Kolom `status` pada `procurement_request_details` (rincian per barang)
  belum ditinjau ulang maknanya terhadap `ProcurementRequestStatus` yang baru
  (status per dokumen). Berpotensi tumpang tindih; ditunda sampai modul
  benar-benar dibangun dan terlihat kebutuhannya.
- Format nomor dokumen resmi BPK untuk `procurement_receipts.bast_number`
  masih harus dikonfirmasi, sama seperti BAST/Nota Dinas milik Alma. Nomor
  pada data contoh nantinya bersifat sementara.
- Empat migrasi baru (`2026_09_05_000001`–`000004`) mengubah tabel warisan
  ERD di atas. Tabel-tabel itu kosong (tidak ada seeder, tidak ada controller
  yang pernah menulis ke sana), sehingga tidak perlu migrasi data.
