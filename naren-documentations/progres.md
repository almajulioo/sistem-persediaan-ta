# Catatan Progres

Urut dari yang terbaru. Satu entri untuk tiap sesi kerja yang menghasilkan
perubahan.

---

## 7 September 2026 — Koreksi realisasi, dan agregat yang jujur

Bermula dari laporan pemakaian: setelah sebuah realisasi dibatalkan, kolom
"Pengadaan" pada daftar penyedia tetap bertambah satu. Usul pertamanya adalah
menghapus baris realisasinya supaya angkanya otomatis benar. Usul itu ditolak,
dan penelusurannya justru memunculkan dua masalah yang lebih dalam. Keputusan
lengkap di [ADR 0025](keputusan/0025-realisasi-dapat-diubah-sebelum-penerimaan.md)
dan [ADR 0026](keputusan/0026-agregat-tidak-menghitung-realisasi-batal.md).

**Yang salah angkanya, bukan datanya.** `SupplierController::index()` memakai
`withCount('procurements')` — mencacah *dokumen*, bukan pengadaan yang
benar-benar terjadi. Kebocoran yang sama ternyata ada di kartu "Jumlah Realisasi"
dan "Nilai Dipesan" pada `/pengadaan` serta rekap laporan PDF. Menariknya,
halaman **detail** penyedia sudah jujur sejak awal karena tiap barisnya membawa
badge status; yang menyesatkan hanya angka ringkasnya.

**Pembatalan sedang dipakai sebagai tombol "ubah".** Pembatalannya terjadi
*karena salah ketik*. Sejak ADR 0024 pembatalan bersifat terminal bagi usulannya,
sehingga satu angka nol yang kelebihan pada kolom harga memaksa Pengelola Gudang
mengajukan usulan baru dari nol, dan meninggalkan satu baris realisasi batal
permanen di riwayat penyedia. `KesalahanPencatatan` yang ditambahkan ADR 0024
sebagai jalan keluar salah ketik ternyata jauh terlalu mahal — padahal selama
status masih `MenungguPenerimaan` belum ada apa pun yang terjadi secara fisik.

**Yang dikerjakan.**

- Rute `GET pengadaan/{procurement}/edit` dan `PUT pengadaan/{procurement}` di
  bawah `kelola-pengadaan`, beserta `UpdateProcurementRequest` dan
  `pages/procurements/edit.blade.php`. Boleh diubah: penyedia, tanggal, jumlah
  pesan, dan harga satuan. Tidak boleh: `procurement_request_id` dan susunan
  barangnya.
- Baris diperbarui **di tempat**, dikunci `procurement_detail_id`, supaya
  formulir penerimaan tetap menunjuk baris yang sama. Kiriman wajib mencakup
  seluruh baris.
- `update()` memanggil `NotificationScanner::periksaRealisasi()` — isi
  `RealisasiMenungguPenerimaan` memuat nama penyedia, jadi basi begitu
  penyedianya diganti.
- Tombol **Ubah Realisasi** diletakkan di atas **Batalkan Realisasi**: urutan
  tombol adalah anjuran tentang mana yang seharusnya lebih sering dipakai.
- `AlasanPembatalan::KesalahanPencatatan` menyempit — tinggal untuk realisasi
  yang dibuat dari usulan yang sama sekali salah, satu-satunya kekeliruan yang
  memang tidak dapat diperbaiki dengan mengubah.
- Agregat di tiga tempat mengecualikan realisasi batal, dengan cacah pembatalan
  selalu ikut ditampilkan: daftar penyedia (`SupplierController::cacahPengadaan()`
  dipakai bersama daftar dan detail), kartu `/pengadaan`, dan rekap laporan PDF.
  Barisnya sendiri **tetap ditampilkan** di mana pun — ditampilkan, tidak
  dihitung.
- Catatan kaki laporan PDF ditulis ulang: sejak nilai batal tidak lagi masuk
  "Nilai Dipesan", selisih dua angka nilai kembali hanya punya dua sebab.
- 22 uji baru: `ProcurementEditTest` (16) plus tambahan di `ProcurementReportTest`
  dan `SupplierManagementTest`.

**Mengapa usul menghapus ditolak.** Tiga akibatnya. Kolom `cancelled_by`,
`cancel_reason`, dan `cancel_note` yang baru dibuat kemarin menjadi sia-sia.
Menghapus barisnya membebaskan kembali indeks unik `procurement_request_id`
sehingga usulan asalnya hidup lagi — membalik keputusan "pembatalan bersifat
terminal" lewat pintu belakang. Dan yang paling menentukan: pola "lima realisasi
dibatalkan kepada penyedia yang sama" justru tepat hal yang ingin dilihat
pemeriksa pada lembaga pemeriksa keuangan; menghapusnya bukan membersihkan data,
melainkan menghapus temuan.

**Temuan saat verifikasi.** Jejak audit sempat menampilkan dua entri "Mengubah
realisasi pengadaan" padahal hanya satu pengubahan yang dilakukan lewat skrip.
Pemeriksaan kronologi `audit_logs` menunjukkan entri pertama berasal dari Naren
sendiri yang sedang mencoba fitur barunya di peramban pada menit yang sama —
dibuat 02:08:46, diubah 02:08:58, dibatalkan 02:09:50. Penjaga statusnya terbukti
benar: realisasi yang sudah `diterima` tidak punya satu pun jejak pengubahan.

**Verifikasi.** 305 test lulus (890 asersi), naik dari 283 — 22 uji baru, tanpa
regresi. Pint bersih pada 23 berkas. `migrate:fresh --seed` berhasil.

**Uji HTTP nyata.** Realisasi dibuat dengan harga sengaja salah (Rp99.999), lalu
diperbaiki lewat formulir Ubah Realisasi menjadi Rp12.500 sekaligus berganti
penyedia: nilai total ikut berubah, jejak audit tercatat, dan isi notifikasi
lonceng gudang menyebut penyedia yang baru. Setelah itu daftar penyedia
menampilkan `1×` dengan `1 dibatalkan` di bawahnya, dan rekap `/pengadaan`
menampilkan `2` dengan `1 dibatalkan` — persis keluhan yang memicu sesi ini.

---

## 7 September 2026 — Menutup celah alur pengadaan

Bukan tahap baru, melainkan tindak lanjut pemeriksaan menyeluruh sehari setelah
modul Pengadaan dinyatakan selesai. Yang ditemukan bukan bug di kode yang baru
ditulis, melainkan dua lubang di **alurnya sendiri**. Keputusan lengkap di
[ADR 0024](keputusan/0024-pembatalan-realisasi-pengadaan.md).

**Jalan buntu yang ditemukan.** `ProcurementStatus::Dibatalkan` ada di enum
sejak Tahap 1 tetapi tidak pernah punya pemicu, dan
[modul/pengadaan.md](modul/pengadaan.md) mencatatnya sebagai "belum ada
kebutuhan lapangan yang mengonfirmasinya". Ternyata kebutuhannya sudah tersirat
di ADR 0016 sendiri: pembelian barang terjadi **di luar sistem**, dan di ruang
itulah penyedia gagal kirim. Ketika itu terjadi, realisasinya terkunci
`MenungguPenerimaan` selamanya, notifikasi `RealisasiMenungguPenerimaan` aktif
selamanya, dan satu-satunya jalan keluar adalah mengonfirmasi penerimaan barang
yang tidak pernah datang — persis kebohongan yang dicegah ADR 0017.

**Titik buta yang ditemukan.** `periksaUsulan()` hanya bekerja selama usulan
`isDiajukan()`, sehingga jarak antara "disetujui" dan "direalisasi" adalah
satu-satunya perpindahan tangan di seluruh alur yang tidak punya penagih.

**Yang dikerjakan.**

- Migrasi `add_cancellation_to_procurements_table` (empat kolom nullable:
  `cancelled_at`, `cancelled_by`, `cancel_reason`, `cancel_note`) dan enum
  `AlasanPembatalan` dengan lima case, meniru bentuk `AlasanSelisih`.
- Rute `PATCH pengadaan/{procurement}/batal` di bawah `kelola-pengadaan`,
  `ProcurementController::cancel()`, dan `CancelProcurementRequest` dengan
  keterangan **wajib** apa pun alasan yang dipilih (pola ADR 0011).
- Pembatalan **hanya dari `MenungguPenerimaan`**, sehingga tidak ada satu pun
  mutasi stok yang perlu dibalik dan `StockLedger` tidak tersentuh sama sekali
  — inilah yang membuat fitur ini aman.
- Dua notifikasi baru: `RealisasiDibatalkan` (peristiwa, ke gudang beserta
  alasan dan keterangannya — keadaan lamanya menutup diri sendiri, tetapi
  menghilang diam-diam tidak cukup bagi orang yang sudah disuruh menunggu
  barang) dan `UsulanBelumDirealisasi` (keadaan, ambang 7 hari, dilipat ke
  dalam `periksaUsulan()` yang sudah ada).
- Saran nomor BAST kini dicacah per tahun berjalan lewat `received_at`;
  sebelumnya memakai `count()` sepanjang masa, sehingga BAST pertama Januari
  2027 akan disarankan `015/BAST/2027`.
- `ConfirmProcurementReceiptRequest` mewajibkan seluruh baris barang terisi.
  Sebelumnya baris yang hilang dari kiriman terbaca `?? 0` — nol diterima,
  **permanen**, karena penerimaan peristiwa sekali jadi tanpa rute ubah. Ini
  alasan yang sama yang membuat ADR 0021 mewajibkan formulir realisasi mencakup
  seluruh rincian usulan yang disetujui.
- `ProcurementRequestSeeder` mendapat usulan kelima (`disetujuiMenganggur`,
  ATK-007, sembilan hari) supaya `UsulanBelumDirealisasi` punya contoh nyata.
  Umurnya sengaja sembilan hari, bukan lebih tua daripada usulan sepuluh hari
  yang sudah ada, karena `ProcurementSeeder` memilih usulan tertua yang belum
  direalisasi dan usulan baru ini tidak boleh merebut gilirannya.

**Keputusan yang paling menentukan.** Pembatalan bersifat **terminal bagi
usulannya**: indeks unik `procurements.procurement_request_id` dipertahankan,
sehingga usulan yang realisasinya batal tidak dapat direalisasikan ulang.
Alternatifnya — melepas indeks unik supaya usulan dapat direalisasi ke penyedia
lain — ditolak karena memindahkan jaminan ADR 0016 dari basis data ke kode
(MySQL tidak mendukung indeks unik parsial). Konsekuensinya harus terlihat di
layar, jadi halaman usulan menjelaskan mengapa tombol "Realisasikan" tidak ada;
tanpa itu, status `Disetujui` tanpa tombol terbaca seperti bug.

**Temuan saat verifikasi.** Pemeriksaan HTTP sempat terlihat seperti menemukan
bug — notifikasi `UsulanBelumDirealisasi` tetap 2 setelah usulan direalisasi.
Ternyata usulan yang direalisasi dan usulan yang ditagih adalah **dua baris
berbeda** yang delapan karakter pertama UUID-nya identik (`01A077D3`), gotcha
UUID v7 yang sudah tercatat di `CLAUDE.md` sejak Tahap 3. Pemeriksaan ulang
dengan UUID penuh membuktikannya benar. Meski begitu satu celah nyata memang
ada dan ikut ditutup: `ProcurementController::store()` belum memanggil
`periksaUsulan()`, sehingga tagihan bertahan sampai sapuan terjadwal berikutnya
— tidak salah menurut ADR 0014, tetapi tidak sejalan dengan
`catatUsulanDiputuskan()` yang menyegarkan keadaan usulannya seketika.

**Verifikasi.** 283 test lulus (825 asersi), naik dari 258 — 25 uji baru, tanpa
regresi. Pint bersih pada 27 berkas. `migrate:fresh --seed` berhasil dan
`notifikasi:periksa` menghasilkan 12 notifikasi aktif, termasuk 2
`UsulanBelumDirealisasi` dari data contoh baru.

**Uji HTTP nyata.** Realisasi dibuat lalu dibatalkan lewat peramban sungguhan:
halaman realisasi menampilkan kartu pembatalan lengkap dengan alasan dan
keterangan, halaman usulan menampilkan penjelasan tanpa tombol "Realisasikan",
`RealisasiMenungguPenerimaan` tertutup, dan lonceng akun `gudang` menerima
"Dibatalkan: Realisasi #01A077D5" beserta alasan "Penyedia Gagal Kirim".

**Catatan.** Masih tersisa satu keadaan tanpa jalan keluar yang setara: usulan
`Disetujui` yang barangnya sudah tidak dibutuhkan hanya akan ditagih terus oleh
`UsulanBelumDirealisasi`. Dicatat sebagai butir menunggu lapangan — kali ini
dengan kesadaran bahwa catatan serupa untuk `Dibatalkan` ternyata menyembunyikan
jalan buntu selama satu tahap penuh.

---

## 6 September 2026 — Pengadaan Tahap 4: Dokumen, BAST, dan laporan

Tahap penutup modul pengadaan, sekaligus penutup seluruh sub sistem. Tidak ada
migrasi baru — `procurement_documents` sudah mendarat di Tahap 1, satu tahap
sebelum fiturnya ditulis. Keputusan baru dicatat di dua ADR:
[ADR 0022](keputusan/0022-wewenang-unggah-dan-hapus-berkas-pengadaan.md)
(wewenang berkas) dan
[ADR 0023](keputusan/0023-laporan-pengadaan-per-baris-barang.md)
(bentuk laporan dan isi BAST).

**Yang dikerjakan.**

- `App\Support\DocumentStorage` sebagai satu-satunya seam penyimpanan berkas:
  path `pengadaan/{procurement_id}/{uuid}.{ext}` di disk privat, pengacakan
  nama, penghapusan, dan pemeriksaan keberadaan berkas. Controller tidak pernah
  menyebut nama disk maupun menyusun path.
- `ProcurementDocumentController` (unggah / unduh / hapus) beserta
  `StoreProcurementDocumentRequest` — `mimes:pdf,jpg,jpeg,png`, maksimal 5 MB.
  Ketiganya di bawah gate `akses-pengadaan`; penghapusan dipersempit lagi di
  controller menjadi pengunggahnya sendiri atau Admin.
- BAST PDF (`pdf/bast-pengadaan.blade.php`): dokumen naratif dua pihak, bukan
  tabel laporan. Seluruh angkanya `amount_received`, dan kiriman kurang
  menghasilkan paragraf catatan yang menyebut jenis serta jumlah kekurangannya.
- Laporan pengadaan PDF + CSV (`procurements.pdf`, `procurements.csv`) berbutir
  **satu baris per barang per realisasi**, mengikuti empat penyaring yang sama
  dengan layar: status, penyedia, tanggal awal, tanggal akhir.
  `Procurement::scopeFilter()` diperluas dari satu penyaring menjadi empat, dan
  kini dipakai bersama oleh layar, PDF, CSV, dan penjaga batas ekspor.
- Rekap di halaman daftar diubah menjadi **mengikuti penyaring** (sebelumnya
  selalu menghitung seluruh basis data) dan ditambah dua kartu nilai: Nilai
  Dipesan dan Nilai Diterima, dihitung lewat subkueri ke `procurement_details`.
  `Procurement::nilaiDiterima()` melengkapi `nilaiTotal()` yang sudah ada.
- Notifikasi keadaan `BastBelumDiunggah` (ambang 3 hari, ke Gudang + Admin),
  dilipat ke dalam `periksaRealisasi()` yang sudah ada sehingga ikut terpicu
  jalur reaktif maupun sapuan terjadwal `notifikasi:periksa`. Ditutup oleh
  unggahan BAST, berlaku lagi bila berkasnya dihapus.
- Kartu "Berkas Pendukung" pada halaman realisasi (daftar + formulir unggah +
  unduh + hapus), tombol "Cetak BAST" pada kartu penerimaan, dan tombol
  Ekspor CSV / Cetak PDF pada halaman daftar — pola tombol yang sama dengan
  halaman Riwayat Mutasi.
- `ProcurementSeeder`: tanggal penerimaan contoh dimundurkan ke 5 hari lalu
  (dari 1 hari) supaya melewati ambang tiga hari — `BastBelumDiunggah` kini
  punya contoh nyata di data awal, sejalan dengan alasan kiriman kurang
  sengaja diseed di Tahap 3.
- 42 uji baru dalam dua berkas: `ProcurementDocumentTest` (25) dan
  `ProcurementReportTest` (17).

**Keputusan yang sempat hampir diambil terbalik.** Godaan pertama adalah
membagi izin unggah menurut jenis dokumen — BAST hanya Pengelola Gudang, SPK
hanya Pejabat Pengadaan — supaya konsisten dengan pemisahan tugas ADR 0016.
Ditolak setelah disadari bahwa yang dipisah ADR 0016 adalah wewenang
**memutuskan**, bukan wewenang melampirkan bukti; sebuah pindaian tidak
menggerakkan status apa pun dan tidak menyentuh stok. Sistem yang menolak
lampiran yang sudah benar tidak membuat orang menunggu orang yang "berhak",
melainkan membuat berkas itu beredar di luar sistem. Yang dibutuhkan adalah
atribusi, dan `uploaded_by` + `audit_logs` sudah menyediakannya. Alasan lengkap
beserta empat alternatif lain di ADR 0022.

**Temuan saat menulis uji.** `$this->actingAs()` di dalam `beforeEach` membuat
sesi uji tetap terautentikasi sepanjang test case, sehingga uji "tamu diarahkan
ke halaman masuk" justru menerima 200. Berkas uji Tahap 2 dan 3 tidak
terpengaruh karena membangun datanya di dalam tiap uji, bukan di `beforeEach`.
Diperbaiki dengan `auth()->logout()` eksplisit di awal uji tamu — bukan dengan
memindahkan pembangunan data, karena `beforeEach` yang berbagi satu realisasi
justru yang membuat berkas uji ini terbaca.

**Verifikasi.** 258 test lulus (762 asersi), naik dari 216 — 42 uji baru, tanpa
regresi. Pint bersih pada 10 berkas baru/ubahan; `routes/web.php` tetap
dilaporkan bermasalah, tetapi isunya pra-ada di blok `permintaan` milik Alma
(dibuktikan dengan menjalankan Pint pada versi `HEAD` berkas itu) sehingga
sengaja tidak disentuh. `migrate:fresh --seed` berhasil dari basis data kosong,
dan `notifikasi:periksa` menghasilkan 2 baris `BastBelumDiunggah` — satu untuk
Pengelola Gudang, satu untuk Admin, persis penerima yang dimaksud.

**Uji HTTP nyata** (server hidup, MySQL, disk sungguhan — bukan `Storage::fake`):
laporan PDF 1,6 MB dan BAST 880 KB keduanya diawali `%PDF-1.7`; CSV memuat
`40;15000;600000;40;0;600000` untuk barang yang lengkap dan
`20;25000;500000;15;5;375000` untuk yang kurang kirim, sehingga dua sisi nilai
ADR 0017 benar-benar terlihat terpisah. Unggahan 880 KB mendarat di
`storage/app/private/pengadaan/{id}/{uuid}.pdf` dengan nama teracak, diunduh
kembali utuh dengan `Content-Disposition` bernama asli `BAST Ditandatangani.pdf`,
dan `BastBelumDiunggah` turun 2 &rarr; 0 seketika. Janji keamanan ADR 0018
diuji langsung: `/storage/{path}` menjawab 403, `/{path}` menjawab 404, dan
rute unduh tanpa sesi mengarahkan ke `/login`. Penghapusan oleh akun
`pengadaan` (bukan pengunggahnya) ditolak dengan pesan dan barisnya tetap ada;
penghapusan oleh `gudang` membuang baris **dan** berkas fisiknya, lalu
`BastBelumDiunggah` berlaku lagi.

**Catatan.** Satu konsekuensi ADR 0018 belum ada yang memanggilnya: menghapus
sebuah pengadaan harus ikut membuang berkasnya lewat `DocumentStorage`, karena
`cascadeOnDelete` pada basis data tidak menyentuh berkas fisik. Rute hapus
pengadaan memang belum ada, jadi tidak ada kode mati yang ditulis untuk itu —
tetapi inilah hal pertama yang harus diingat bila rute itu kelak dibuat.

---

## 6 September 2026 — Pengadaan Tahap 3: Realisasi dan Penerimaan

Inti modul pengadaan: realisasi dari usulan yang disetujui, dan konfirmasi
penerimaan yang benar-benar menambah stok. Tidak ada migrasi baru — seluruh
kolom yang dibutuhkan (`unit_price`, `amount_received`) sudah mendarat di
Tahap 1. Keputusan lengkap di
[ADR 0021](keputusan/0021-realisasi-dan-penerimaan-pengadaan.md).

**Yang dikerjakan.**

- `ProcurementController` (realisasi, gate `kelola-pengadaan` untuk
  create/store, `akses-pengadaan` untuk index/show) dan
  `ProcurementReceiptController` (penerimaan, gate `kelola-gudang`,
  create/store saja — peristiwa sekali jadi tanpa rute ubah) — tiga
  controller terpisah sesuai rancangan ADR 0016.
- `App\Support\ProcurementReceiptPoster`, mengikuti pola `OpnamePoster`:
  transaksi terkunci, `StockLedger::record(type: Masuk, amount: amount_received)`
  per barang (baris nol tidak menghasilkan mutasi), status realisasi menjadi
  `Diterima`, baris `ProcurementReceipt` dibuat, lalu `AuditLogger` dan
  notifikasi dipanggil di luar transaksi.
- `StoreProcurementRequest` (form request realisasi) menurunkan `item_id`
  server-side dari `procurement_request_detail_id` lewat `rincianDisetujui()`,
  pola yang sama dengan `ApproveProcurementRequestRequest` Tahap 2 — klien
  tidak pernah mengirim `item_id` secara langsung.
  `ConfirmProcurementReceiptRequest` memvalidasi jumlah diterima tanpa batas
  atas (hanya kekurangan kiriman yang jadi perhatian ADR 0017).
- Tombol "Realisasikan" pada halaman usulan (tampil hanya bila `Disetujui`
  dan belum punya realisasi) dan "Konfirmasi Penerimaan" pada halaman
  realisasi (tampil hanya bila masih `MenungguPenerimaan`) — pola jalur masuk
  yang sama dengan tombol usulan pengadaan pada halaman barang di Tahap 2.
- `NotificationScanner::periksaRealisasi()`/`periksaSeluruhRealisasi()`/
  `catatPenerimaanDikonfirmasi()`, dan dua jenis notifikasi baru sesuai
  rencana yang sudah dicatat sejak modul Notifikasi dibangun:
  `RealisasiMenungguPenerimaan` (keadaan, ke Pengelola Gudang + Admin) dan
  `PenerimaanDikonfirmasi` (peristiwa, ke Pejabat Pengadaan yang merealisasi).
- `ProcurementSeeder`: satu realisasi contoh dibawa **sampai diterima lewat
  `ProcurementReceiptPoster::post()` sungguhan** (bukan baris yang ditulis
  langsung ke tabel), sengaja dibuat diterima sebagian (tinta warna kurang 5
  botol) supaya `ProcurementDetail::kurangKirim()` punya contoh nyata.
  `ProcurementRequestSeeder` mendapat usulan keempat (`disetujuiPenuh`,
  ATK-012) yang sengaja dibiarkan belum direalisasikan untuk mendemokan
  tombol "Realisasikan".
- 15 uji baru: matriks akses tiga gate (realisasi) dan gate tunggal
  (penerimaan), validasi (usulan belum disetujui, realisasi ganda, jumlah
  melebihi disetujui), jalur pintas dari halaman usulan, dan yang paling
  penting — uji yang membuktikan stok bertambah sebanyak `amount_received`
  bukan `amount` lewat HTTP sungguhan.

**Temuan saat verifikasi manual (bukan bug, tapi layak dicatat).** Dua
dokumen yang dibuat pada detik yang sama saat `migrate:fresh --seed`
menampilkan label 8-karakter (`judul()`) yang identik di tampilan
(`Realisasi #01A076E9` dan `Usulan #01A076E9`) padahal UUID sebenarnya
berbeda total. Penyebabnya `HasUuids` menghasilkan UUID v7 berurut yang
prefiksnya berbasis waktu; kemunculannya sama karena dibuat pada milidetik
yang berdekatan dalam satu proses seeding. Tidak memengaruhi fungsi apa pun
(route model binding dan relasi memakai UUID penuh), tetapi dicatat sebagai
gotcha di `CLAUDE.md` supaya tidak disangka bug saat pemeriksaan berikutnya.

**Verifikasi.** 216 test lulus (656 asersi), naik dari 201 — 15 uji baru,
tanpa regresi. Pint bersih pada seluruh 13 berkas baru/ubahan inti (dua
berkas lain — `MenuHelper.php` dan `routes/web.php` — punya isu gaya
pra-ada di blok milik Alma, sengaja tidak disentuh sesuai aturan cakupan
sempit). `migrate:fresh --seed` berhasil dari basis data kosong; pemeriksaan
lewat `tinker` membuktikan stok ATK-003 bertambah 40 (kiriman penuh) dan
ATK-004 bertambah 15 dari 20 dipesan (kiriman kurang, `kurangKirim()` = 5),
notifikasi `PenerimaanDikonfirmasi` tercatat 1 baris, dan keadaan
`RealisasiMenungguPenerimaan` tertutup begitu diterima.

**Berikutnya.** Tahap 4 — Dokumen, BAST, dan laporan: unggahan berkas ke disk
privat, BAST PDF, ekspor PDF/CSV, dan notifikasi keadaan `BastBelumDiunggah`.

---

## 6 September 2026 — Pengadaan Tahap 2: Usulan Pengadaan

Modul Pengadaan sesungguhnya, menyusul rancangan skema Tahap 1. Tidak ada
migrasi baru — seluruh kolom yang dibutuhkan (`source`, `status`,
`amount_granted`) sudah mendarat di Tahap 1.

**Keputusan yang baru diambil saat pengodean** (belum ditetapkan ADR 0016):
bagaimana persetujuan sebagian ditangkap, dan katalog notifikasi usulan.
Keduanya didokumentasikan di
[ADR 0020](keputusan/0020-notifikasi-usulan-dan-persetujuan-sebagian.md):

- Persetujuan **satu dokumen sekali putus** lewat peta `granted[]` per barang,
  bukan satu aksi per barang — usulan dengan banyak barang tetap satu
  permintaan HTTP untuk satu keputusan.
- Menolak mengisi `amount_granted = 0` eksplisit pada seluruh barang, karena
  `null` sudah bermakna "belum diputuskan" pada model.
- Empat notifikasi baru: `UsulanMenungguPersetujuan`/`UsulanMenggantung`
  (keadaan, saling eksklusif dengan ambang 7 hari, meniru pola
  `OpnameSiapDiposting`/`OpnameDrafMenggantung`), `UsulanDisetujui`/`UsulanDitolak`
  (peristiwa ke pengaju).
- `source` bukan field pilihan pengguna — diturunkan dari titik masuk
  formulir (tombol pada halaman barang vs navigasi langsung). Sumber
  `DariPermintaan` sengaja diblokir di validasi: integrasi ke sub sistem
  Permintaan belum punya titik masuk aplikasi.

**Yang dikerjakan.**

- `ProcurementRequestController`: `index`/`show` di bawah gate `akses-pengadaan`
  (kedua peran baca), `create`/`store` di bawah `kelola-gudang` (Pengelola
  Gudang mengusulkan), `approve`/`reject` di bawah `kelola-pengadaan` (Pejabat
  Pengadaan memutuskan) — tiga gate berbeda pada satu controller, pola baru di
  proyek ini.
- `StoreProcurementRequestRequest` dan `ApproveProcurementRequestRequest`;
  yang kedua memvalidasi `granted.*` terhadap `amount_request` sesungguhnya
  lewat `withValidator()` yang membaca model rute, pola yang sama dengan
  `UpdateOpnameCountRequest`.
- Tombol "Ajukan Usulan Pengadaan" pada halaman barang, muncul hanya saat
  status stok Habis/Menipis dan pengguna punya gate `kelola-gudang`; membawa
  `?item_id=` ke formulir usulan yang otomatis memilihkan barangnya.
- `NotificationScanner::periksaUsulan()`/`periksaSeluruhUsulan()`/
  `catatUsulanDiputuskan()`, mendampingi method sejenis untuk stok dan opname.
- Tiga halaman (`index`, `create`, `show`) — pemilih barang pada `create`
  memakai ulang pola pencarian Alpine.js yang sudah ada pada formulir
  permintaan barang (Alma), disesuaikan nama field dan tanpa batas stok
  (usulan pengadaan justru mengusulkan menambah stok).
- `ProcurementRequestSeeder`: tiga usulan contoh (Diajukan, Disetujui
  sebagian, Ditolak) supaya modul langsung bisa dicoba.
- 22 uji baru: matriks akses tiga gate, validasi (barang kosong, duplikat,
  jumlah nol, sumber diblokir), persetujuan sebagian, penolakan mengisi nol,
  penjagaan usulan yang sudah diputuskan, dan jalur pintas dari halaman
  barang.

**Gotcha yang ditemukan.** Rute `usulan-pengadaan/create` (GET) harus
terdaftar sebelum `usulan-pengadaan/{procurementRequest}` (GET) di
`routes/web.php`, walau keduanya berada di grup gate yang berbeda — kalau
tidak, "create" tertangkap sebagai parameter dan gagal *route model binding*.
Diselesaikan dengan urutan pendaftaran grup, dicatat sebagai gotcha di
`CLAUDE.md`.

**Verifikasi.** 201 test lulus (608 asersi), naik dari 179 — 22 uji baru,
tanpa regresi. Pint bersih pada seluruh berkas baru/ubahan (satu isu gaya
pra-ada pada `MenuHelper.php` milik blok Alma sengaja tidak disentuh, sesuai
aturan cakupan sempit). `migrate:fresh --seed` **belum diverifikasi** sesi ini
karena MySQL (XAMPP) tidak berjalan di lingkungan pengembangan saat itu —
tidak ada migrasi baru yang berisiko, tetapi tetap perlu dijalankan sebelum
Tahap 3 dimulai.

**Berikutnya.** Tahap 3 — Realisasi dan Penerimaan: `ProcurementReceiptPoster`
yang menambah stok lewat `StockLedger` sebanyak `amount_received`, bukan
`amount` (ADR 0017).

---

## 6 September 2026 — Pengadaan Tahap 1: rancangan alur, skema, dan Penyedia

Sesi perancangan modul Pengadaan, dilanjutkan pengerjaan tahap pertamanya.
Modul ini dipecah **empat tahap** karena jauh lebih besar dari modul mana pun
sebelumnya; ini tahap pertama.

**Batas cakupan yang disepakati.** Sistem hanya menangkap proses pengadaan di
dalam kantor: usulan → realisasi → penerimaan. Pembelian barangnya sendiri
terjadi di luar sistem, di antara realisasi dan penerimaan.

**Temuan utama diskusi: penerimaan harus boleh jujur.**
`procurement_details` cuma punya satu kolom jumlah (`amount`), sehingga stok
akan bertambah sebanyak yang *dipesan*. Padahal penyedia sering mengirim
kurang. Tanpa tempat mencatat "pesan 100, datang 80", petugas hanya punya tiga
pilihan yang semuanya buruk: tidak mengonfirmasi (stok sistem tertinggal dari
fisik), mengonfirmasi angka palsu (opname berikutnya memunculkan selisih
misterius), atau menambal lewat mutasi `KoreksiPencatatan` yang namanya
berbohong — kekurangan kiriman bukan kesalahan pencatatan. Kolom
`amount_received` menutup ketiganya sekaligus. Ini perubahan tunggal paling
menentukan di modul ini ([ADR 0017](keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md)).

**Kesalahan urutan yang ikut ketahuan.**
`procurement_receipts.file_url` bersifat **NOT NULL**, artinya penerimaan tidak
dapat dikonfirmasi sebelum berkas BAST ada. Padahal urutan fisiknya justru
sebaliknya: cetak → tanda tangan → pindai. Skema lama memblokir pencatatan stok
di belakang alur pemindaian. Sekarang berkasnya boleh menyusul, ditagih
notifikasi keadaan yang menutup diri sendiri
([ADR 0018](keputusan/0018-berkas-unggahan-di-disk-privat.md)).

**Butir terbuka ADR 0015 ditutup.** Kolom `status` pada
`procurement_request_details` dihapus — ia duplikat `amount_granted` (null =
belum diputuskan, 0 = ditolak, N = disetujui sejumlah N), tanpa enum maupun
cast, dan menyimpan keduanya berarti dua sumber kebenaran.

**Yang dikerjakan.**

- Enam migrasi: `suppliers` (baru), `procurement_documents` (baru),
  `procurements` ditautkan ke penyedia, `unit_price` + `amount_received` pada
  rincian realisasi, penghapusan `status` pada rincian usulan, dan penghapusan
  `file_url` pada penerimaan.
- Enum `ProcurementDocumentType`; model `Supplier` dan `ProcurementDocument`;
  pemutakhiran `Procurement`, `ProcurementDetail`, `ProcurementRequestDetail`,
  dan `ProcurementReceipt`.
- Gate baru `akses-pengadaan` — kedua peran perlu membaca dokumen pengadaan
  yang sama, sedangkan `can:` yang ditumpuk bersifat DAN, bukan ATAU.
- Modul **Penyedia Barang** lengkap: controller, trio form request, empat
  halaman, rute, factory, seeder, menu.
- Empat ADR: [0016](keputusan/0016-alur-pengadaan-tiga-dokumen.md) (alur tiga
  dokumen dan pemisahan tugas), [0017](keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md),
  [0018](keputusan/0018-berkas-unggahan-di-disk-privat.md), dan
  [0019](keputusan/0019-harga-di-realisasi-tanpa-penilaian-persediaan.md)
  (harga dicatat, penilaian persediaan di luar cakupan).

**Celah yang sekalian ditutup.** `User` tidak punya relasi
`procurementReceipts()` maupun entrinya di `User::RESTRICTED_RELATIONS`,
padahal FK `received_by` sudah `restrictOnDelete()` — pengarsipan akun bisa
salah hitung.

**Catatan.** `CLAUDE.md` mulai sesi ini ditulis dalam bahasa Inggris atas
permintaan Narendra. Dokumentasi di folder ini tetap bahasa Indonesia.

**Verifikasi.** 179 test lulus (161 dasar + 18 baru), tanpa regresi. Pint bersih
pada 29 berkas cakupan sempit. `migrate:fresh --seed` membuktikan seluruh 34
migrasi berjalan dari basis data kosong, termasuk penambahan kolom NOT NULL
tanpa default pada tabel yang sudah ada.

**Berikutnya.** Tahap 2 — Usulan Pengadaan beserta notifikasinya.

---

## 5 September 2026 — Perbaikan skema Pengadaan sebelum modulnya dibangun

Sebelum menulis satu baris controller pun untuk modul Pengadaan, migrasi
warisan ERD-nya (`procurements`, `procurement_details`, `procurement_requests`,
`request_details`) dibaca ulang untuk memastikan tidak ada yang perlu
disepakati dengan Alma lebih dulu — CLAUDE.md mencatatnya sebagai syarat wajib.

**Yang tadinya dikira tiga "perbatasan" ternyata bukan perbatasan sama
sekali.** Pembacaan literal terhadap migrasi menyimpulkan tiga hal perlu
dibahas dengan Alma: kontrak `procurement_requests.request_id → requests`,
kepemilikan `basts`/`nota_dinas`, dan sumber nomor dokumen. Setelah
diklarifikasi langsung, ketiganya bubar sekaligus:

- `procurement_requests.request_id` **bukan foreign key yang disengaja** —
  sisa kebingungan penamaan primary key (`procurement_request_id` yang
  sekarang sudah benar sejak awal). Bukan relasi apa pun.
- `request_details` **ternyata bukan rincian permintaan unit milik Alma**,
  melainkan rincian usulan pengadaan milik sub sistem ini sejak awal — salah
  nama dan salah arah FK.
- `basts`/`nota_dinas` **sepenuhnya milik Alma**. BAST penerimaan barang dari
  penyedia ke gudang (sisi Pengadaan) memang belum pernah punya tempat di
  skema — desain awal cuma bermaksud menyimpannya sebagai berkas hasil pindai
  lewat kolom string bebas (`procurements.bast_scan`), dan itu sekarang
  dinilai desain yang kurang baik.

Sekaligus, narasumber lapangan (Kasubbag Umum dan TI) mengonfirmasi usulan
pengadaan lahir dari **tiga jalur berbeda**: permintaan unit yang stoknya
kurang, pemantauan stok otomatis (notifikasi Stok Habis/Menipis yang sudah
dibangun), dan diajukan manual oleh Pengelola Gudang.

**Hasilnya: tidak ada lagi satu pun butir yang menunggu kesepakatan dengan
Alma sebelum modul Pengadaan dibangun.** Lihat
[ADR 0015](keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) untuk
keputusan skema lengkap.

**Yang dikerjakan.**

- Tiga enum baru: `ProcurementRequestSource` (tiga asal-usul usulan),
  `ProcurementRequestStatus` (Diajukan/Disetujui/Ditolak, status usulan),
  `ProcurementStatus` (MenungguPenerimaan/Diterima/Dibatalkan, status
  realisasi — nama case `MenungguPenerimaan` sengaja memakai istilah persis
  dari ADR 0008). Status dipecah jadi dua enum, bukan satu enum lima-tahap
  tunggal, karena usulan dan realisasi adalah dua dokumen berbeda.
- Empat migrasi susulan: `procurement_requests` (hapus `request_id`/
  `request_type`, tambah `source`/`reference_type`/`reference_id`/`status`),
  rename `request_details` → `procurement_request_details` (PK dan FK
  diperbaiki menunjuk `procurement_requests`), `procurements` (tambah
  `procurement_request_id` wajib+unik, hapus `bast_scan`), dan tabel baru
  `procurement_receipts` (BAST penerimaan, `received_by` sengaja terpisah
  dari pengaju realisasi — pemisahan tugas ADR 0008 tercermin di skema, bukan
  cuma alur aplikasi).
- `reference_type`/`reference_id` polimorfik nullable pada
  `procurement_requests`, pola yang sama dengan `stock_mutations.reference_*`
  (ADR 0007), lewat alias morph map `permintaan` yang sudah terdaftar. Diisi
  hanya untuk sumber `DariPermintaan` — **bukan kewajiban**, sub sistem
  Pengadaan berfungsi penuh tanpa sub sistem Permintaan ada.
- Model diperbarui: `ProcurementRequest`, `Procurement`; model baru
  `ProcurementReceipt`; `RequestDetail` dihapus, diganti
  `ProcurementRequestDetail`.
- Perbaikan tak terhindarkan pada dua model warisan bersama:
  `App\Models\Request::details()`/`procurementRequests()` dihapus (dibangun
  di atas premis yang keliru — `requests` milik Alma belum punya tabel
  rincian barangnya sendiri sama sekali), dan `Item::requestDetails()`
  diganti nama `procurementRequestDetails()` beserta label pada
  `RESTRICTED_RELATIONS`.
- `naren-documentations/erd/penyelarasan-erd.md` ditulis ulang total: bagian
  "Perbatasan" dihapus, riwayat kesalahan desain dicatat eksplisit, dan
  atribusi Temuan #4 yang dulu keliru (sempat menuduh gambar ERD Alma yang
  salah, padahal migrasi Naren sendiri yang keliru) diperbaiki.

**Verifikasi.** 161 test tetap lulus (500 asersi) — tidak ada regresi. Pint
bersih pada 13 berkas dalam cakupan sempit. Keempat migrasi dijalankan di
MySQL setelah dipastikan seluruh tabel terkait kosong (tidak ada seeder untuk
Procurement/Request/Bast/NotaDinas, tidak ada controller yang pernah menulis
ke sana) — tidak perlu migrasi data.

**Berikutnya.** Modul Pengadaan sesungguhnya: controller, form request, view,
dan uji 4-kasus hak akses per aksi — mengikuti pola modul gudang. Kandidat
notifikasi untuk modul ini sudah dicatat di
[modul/notifikasi.md](modul/notifikasi.md).

---

## 4–5 September 2026 — Modul Notifikasi

Fitur kecil yang disisipkan sebelum modul Pengadaan, sekaligus menyiapkan
lahannya: modul Pengadaan tinggal menambah case enum untuk ikut memakainya.

**Keputusan inti — notifikasi stok bukan peristiwa, melainkan keadaan.**
Permintaan awalnya sederhana ("beri tahu kalau stok habis atau menipis"), tetapi
bentuk datanya tidak. "Stok kertas habis" tidak *terjadi* pada satu titik waktu;
ia **sedang benar**, dan bisa berhenti benar tanpa siapa pun menekan tombol.

Dua jalan buntu yang dihindari:

| Pendekatan | Akibatnya |
|---|---|
| Satu notifikasi per mutasi | Barang yang sudah habis tetap dimutasi. Lonceng terisi puluhan baris berbunyi sama dalam hitungan hari, lalu diabaikan orang |
| Satu notifikasi yang tak pernah ditutup | "Stok kertas habis" tetap terpampang setelah diisi kemarin — **menyesatkan**, dan itu lebih mahal daripada tidak ada |

Jadi tiap jenis menyatakan dirinya keadaan atau peristiwa lewat
`NotificationType::bersifatKeadaan()`. Keadaan menutup dirinya sendiri
(`resolved_at`) begitu pulih; peristiwa tidak pernah ditutup dan hilang setelah
dibaca ([ADR 0013](keputusan/0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md)).

Penopangnya satu hal teknis: indeks unik `(user_id, dedup_key)`, sehingga
seluruh pemeriksaan **idempoten**. `Notifier::terapkanKeadaan()` menerima sebuah
boolean, bukan perintah "kirim" — pemanggil tidak pernah perlu tahu apakah
notifikasinya sudah ada.

**Temuan teknis yang menentukan arah — `DB::afterCommit()` tidak pernah jalan di
bawah `RefreshDatabase`.** Rencana awal memasang hook otomatis di dalam
`StockLedger::record()` supaya semua pemanggil termasuk sub sistem Alma ikut
terlayani tanpa perlu ingat memanggil apa pun. Sebelum dipakai, mekanismenya
diperiksa langsung ke kode kerangka kerja:

- `DatabaseTransactionsManager::afterCommitCallbacksShouldBeExecuted()`
  mengembalikan `$level === 0` — callback hanya jalan saat transaksi terluar
  benar-benar commit.
- `RefreshDatabase::beginDatabaseTransaction()` membungkus **setiap uji** dalam
  transaksi yang di-rollback, tidak pernah di-commit.

Artinya level tidak pernah kembali ke nol di dalam uji, dan callback-nya tidak
pernah jalan sama sekali. Memilih mekanisme itu berarti membangun fitur yang
tidak dapat diuji. Diganti pemanggilan eksplisit seperti pola `AuditLogger`
([ADR 0014](keputusan/0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md)).

Yang menyelamatkan keputusan itu dari kerapuhannya adalah idempotensi tadi:
**pemeriksaan reaktif hanyalah percepatan, bukan syarat kebenaran.** Jalur yang
lupa memanggilnya tetap tertangkap pemeriksaan terjadwal; yang hilang cuma
kesegeraan, bukan datanya.

**Katalog disaring dengan dua ujian** — penerimanya belum tahu, dan ada yang bisa
dilakukan. Sepuluh jenis lolos: empat keadaan stok, tiga keadaan opname, tiga
peristiwa. Yang dibuang antara lain "Anda mencatat mutasi" (gagal ujian pertama)
dan "akun Anda dinonaktifkan" (gagal ujian kedua — penerimanya sudah ditendang
keluar sesi).

Tiga jenis yang tidak terpikir di permintaan awal tetapi bernilai tinggi:

- **Mutasi Manual Sensitif** — Rusak/Hilang/Kadaluarsa/Koreksi mengurangi stok
  **tanpa dokumen sumber**. Pada instansi pemeriksa, justru inilah yang paling
  layak diketahui Admin. Pelakunya sendiri tidak diberi tahu.
- **Stok Bergerak Setelah Dicacah** — `OpnameDetail::stokBerubahSejakDicacah()`
  sudah ada sejak modul opname tetapi **tidak pernah ditampilkan kepada siapa
  pun**.
- **Periode Belum Diopname** — memanfaatkan enum `PeriodeOpname` yang sudah ada.

**Yang dikerjakan.**

- Dua enum: `NotificationType` (10 case), `NotificationLevel` (Mendesak /
  Perhatian / Info, dengan `bobot()` untuk pengurutan).
- Migrasi `user_notifications` — **bukan** `notifications`, karena `User` sudah
  memakai trait `Notifiable` yang mengklaim nama itu beserta bentuk kolomnya.
- `app/Support/Notifier.php` dan `app/Support/NotificationScanner.php`,
  mendampingi `StockLedger`, `AuditLogger`, dan `OpnamePoster`. Keduanya menelan
  kegagalannya sendiri ke log, meniru `AuditLogger`.
- **Penjadwal pertama di proyek ini**: `php artisan notifikasi:periksa`,
  dijadwalkan harian 06.00 di `routes/console.php`.
- Lonceng header dirombak dari dummy TailAdmin ("Terry Franci",
  `console.log`) menjadi data nyata: badge berangka, dua bagian "Perlu Tindakan"
  dan "Aktivitas Terbaru", tombol tandai semua dibaca.
- Halaman `/notifikasi` dengan tiga penyaring dan kartu rekap. Disajikan sebagai
  **daftar, bukan tabel** — isi notifikasi berupa kalimat utuh yang tidak
  terbaca dalam sel sempit.
- Pemicu reaktif di `StockMutationController`, `ItemController` (4 titik), dan
  `OpnamePoster::post()`.
- Ikon `bell` dan entri menu "Notifikasi" di `MenuHelper`, tanpa kunci `roles`.

**Verifikasi.** 161 uji lulus (500 asersi), naik dari 142 — 19 uji baru. Pint
bersih pada 16 berkas dalam cakupan sempit. Migrasi berjalan di MySQL. Siklus
penuh diuji pada data nyata: `ATK-001` dikuras → 2 notifikasi (admin + gudang)
dengan kalimat tindak lanjut; diperiksa 2× lagi → **tetap 2 baris**; diisi ulang
→ **0 aktif, 2 baris tetap ada** (ditutup, bukan dihapus). Perintah terjadwal
pada data contoh menghasilkan tepat satu keadaan yang benar: Triwulan I 2026
belum diopname, sedangkan Triwulan II dan III sudah punya dokumen dan Triwulan
IV belum mendekati akhir.

**Catatan.** Verifikasi manual itu menulis 2 mutasi nyata berketerangan "Uji
coba" pada `ATK-001` di basis data pengembangan (stok 132 → 0 → 40). Baris
mutasi immutable sehingga tidak dihapus; `php artisan migrate:fresh --seed`
mengembalikan data contoh bila diperlukan.

**Berikutnya.** Modul Pengadaan, dengan syarat yang tidak berubah:
**penyelarasan tabel perbatasan bersama Alma lebih dulu**, dan wajib menerapkan
[ADR 0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md). Notifikasi
untuk modul itu cukup menambah case enum — kandidatnya sudah dicatat di
[modul/notifikasi.md](modul/notifikasi.md).

---

## 4 September 2026 — Modul Stok Opname

Modul terakhir sub sistem manajemen gudang. Dibangun dari activity diagram yang
sudah dibuat, dengan empat perbaikan: dokumen laporan dibuat **setelah** posting
(pada diagram ia bercabang paralel sehingga jadi sebelum alasan dimasukkan),
tambahan keadaan **draf** karena pencacahan tidak selesai dalam satu duduk,
**periode terstruktur** karena laporan diminta per triwulan, dan penanganan
**stok yang bergerak saat opname berjalan** yang tidak disinggung diagram.

**Yang dikerjakan.**

- Tiga enum baru: `PeriodeOpname` (8 case), `AlasanSelisih` (6 case),
  `OpnameStatus`.
- Dua migrasi: kolom alur dan periode pada `stock_opnames`; kolom alasan dan
  jejak posting pada `opname_details`, sekaligus melonggarkan `stock_real` dan
  `difference` menjadi nullable agar draf dapat menyimpan barang yang belum
  dicacah.
- `app/Support/OpnamePoster.php` — penerapan hasil opname dalam satu transaksi
  berkunci, mendampingi `StockLedger` dan `AuditLogger`.
- Alur draf → selesai: draf boleh sebagian, posting wajib lengkap dan setiap
  selisih wajib beralasan, opname selesai bersifat final
  ([ADR 0010](keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md)).
- Alasan selisih memakai enum baku **berpasangan** keterangan bebas, keduanya
  wajib ([ADR 0011](keputusan/0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md)).
- Periode disimpan sebagai enum jenis + tahun, terpisah dari `opname_date`
  ([ADR 0012](keputusan/0012-periode-opname-enum-jenis-dan-tahun.md)).
- Empat halaman (`index`, `create`, `edit`, `show`) dan Laporan Stok Opname PDF
  lanskap, memakai ulang infrastruktur laporan modul mutasi.

**Temuan penting — rancangan yang sempat disetujui ternyata salah.** Rencana
awal menetapkan penyesuaian sebagai `stock_real − stock_at_posting`, yaitu
memaksa stok akhir sama dengan hasil cacah. Verifikasi manual lewat HTTP
membuktikannya keliru:

| Langkah | Stok |
|---|---|
| Stok tercatat saat draf dibuat | 132 |
| Hasil cacah — cocok, tidak ada kesalahan pencatatan | 132 |
| Setelah pencacahan, 20 unit dicatat rusak lewat mutasi manual | 112 |
| Posting dengan rumus lama: `132 − 112 = +20` | **132** |

Posting **membatalkan kerusakan yang sah tercatat**. Rumus itu memperlakukan
seluruh jarak antara hasil cacah dan stok terkini sebagai kesalahan pencatatan,
padahal sebagian di antaranya justru transaksi yang benar.

Diperbaiki menjadi: yang diterapkan adalah **`difference`**, selisih yang
ditemukan saat mencacah. Stok akhir = `stock_at_posting + difference`, sehingga
transaksi setelah pencacahan tidak ikut dibatalkan. `OpnamePoster`, controller,
`show.blade.php`, templat PDF, dan uji ikut disesuaikan; satu uji baru
ditambahkan khusus untuk kasus "pencatatan cocok tetapi stok berubah setelahnya".

**Bug lain yang ditemukan dan diperbaiki dalam sesi ini.**

- `UpdateOpnameCountRequest` memvalidasi sebelum penjaga "sudah diposting" di
  controller, sehingga opname selesai memunculkan pesan selisih yang menyesatkan.
  Diperbaiki dengan keluar lebih awal di `withValidator`.
- `StockOpnameSeeder` berbagi koleksi barang yang sudah basi antara dua
  methodnya, sehingga `stock_stored` pada draf tidak sama dengan stok
  sebenarnya. Tiap method kini mengambil barang segar.
- Dua uji bergantung pada urutan yang tidak deterministik
  (`details->first()` dan `latest('created_at')` pada baris sedetik) — diganti
  `keyBy('item_id')` dan pembacaan URL redirect.

**Verifikasi.** 142 test lulus (443 asersi). Pint bersih pada 31 berkas dalam
cakupan sempit. Uji HTTP: draf berisi seluruh 15 barang aktif dengan stok
tercatat yang benar; posting ditolak selama masih ada yang belum dicacah;
selisih tanpa alasan ditolak; PDF diawali `%PDF`; `pengadaan` menerima 403 dan
`admin` menerima 200.

**Catatan.** `stock_opnames` dan `opname_details` **bukan tabel bersama**,
sehingga seluruh perubahan skema di sesi ini tidak perlu kesepakatan dengan
Alma — cukup dicatat di
[penyelarasan-erd.md](erd/penyelarasan-erd.md) agar gambar ERD naskah Naren
diperbarui.

**Berikutnya.** Sub sistem manajemen gudang selesai. Modul Pengadaan
**wajib menyelaraskan tabel perbatasan lebih dulu bersama Alma**, dan wajib
menerapkan [ADR 0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md):
realisasi berhenti di status *Menunggu Penerimaan*, dan Pengelola Gudang yang
mengonfirmasi penerimaan sehingga stok bertambah.

---

## 3 September 2026 — Dokumentasi dipisah per pengembang

**Latar belakang.** Narendra dan Alma menulis **skripsi terpisah dengan judul
berbeda**, masing-masing tentang sub sistemnya sendiri. Konsekuensinya ERD
keduanya pun berbeda — dan itu **memang benar**, bukan kesalahan yang harus
disatukan menjadi satu gambar.

Framing sebelumnya keliru: dokumentasi memperlakukan perbedaan ERD sebagai
selisih yang harus diperbaiki seluruhnya.

**Yang dikerjakan.**

- Folder `documentations/` diganti nama menjadi `naren-documentations/`, karena
  Alma memelihara dokumentasinya sendiri.
- Sebutan "rekan" diganti nama sebenarnya, **Alma**, di seluruh dokumentasi.
- `erd/penyelarasan-erd.md` ditulis ulang: dari "selisih ERD yang harus
  diperbaiki" menjadi **peta kepemilikan tabel**. Kini memuat empat kelompok —
  tabel bersama (wajib identik), milik Naren, milik Alma, dan perbatasan yang
  perlu kesepakatan. Keempat temuan lama tetap ada, tetapi dikelompokkan menurut
  siapa yang berhak memperbaikinya.
- `README.md` root menegaskan bahwa ERD berbeda itu wajar, dan menyebut keempat
  tabel bersama beserta aturannya.

**Catatan.** Temuan #1 (`request_date` pada `procurement_requests`) ternyata milik
Naren sendiri, jadi dapat dikerjakan tanpa menunggu kesepakatan. Temuan #2 dan #3
berada di perbatasan, #4 milik Alma.

---

## 3 September 2026 — Koreksi semantik riwayat mutasi + dokumentasi proyek

**Latar belakang.** Klarifikasi bersama pengguna menemukan bahwa data contoh dan
sebagian semantik modul mutasi menanamkan **asumsi bisnis yang salah**. Berbahaya
justru karena tidak terlihat sebagai bug: sistemnya jalan, tetapi mengajarkan
proses yang keliru.

Tiga hal yang terbukti keliru saat diperiksa terhadap data nyata:

| Temuan | Bukti |
|---|---|
| Nomor referensi `PG01001`/`PR01001` tidak menunjuk apa pun | `reference` terisi 0 dari 77 baris |
| Keterangan "pengadaan **triwulan**" | Kadensi yang tidak pernah disebutkan, dikarang seeder |
| Atribusi petugas Barang Masuk tanpa dasar | Dipilih tanpa alasan yang dinyatakan |

**Yang dikerjakan.**

- Aturan proses baru: penerimaan barang dikonfirmasi **Pengelola Gudang**, bukan
  Pejabat Pengadaan — pemisahan tugas ([ADR 0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md)).
  Ini menentukan rancangan modul Pengadaan: realisasi berhenti di status
  *Menunggu Penerimaan*.
- Kolom `reference` diganti `reference_id`, menjadi relasi polimorfik Laravel
  dengan morph map ([ADR 0007](keputusan/0007-kolom-audit-dan-referensi-polimorfik.md)).
  Parameter `$referenceType` dihapus dari `StockLedger::record()`; jenis dokumen
  diturunkan dari `getMorphClass()`.
- Kolom **Sumber** ditambahkan di layar, PDF, dan CSV.
- Data contoh diperbaiki: kata "triwulan" dan kode karangan dihapus, keterangan
  dirakit dari data dokumen yang realistis.
- Folder `documentations/` dibuat beserta 9 ADR, 4 dokumen modul, ikhtisar
  sistem, dan catatan progres ini. `README.md` root dirampingkan menjadi hanya
  berisi hal yang diperlukan untuk pengembangan bersama.

**Bug yang ditemukan dan diperbaiki dalam sesi ini.** Seeder menulis
`reference_type` ke baris yang **salah**: seluruh baris dibuat pada detik yang
sama sehingga `latest('created_at')` mengembalikan baris sembarang — ternyata
mengenai `saldo_awal`. Akibatnya 14 baris saldo awal salah berlabel Permintaan,
dan 44 baris jatuh ke Sumber "Sistem". Diperbaiki dengan memakai objek yang
dikembalikan `StockLedger::record()` alih-alih mencarinya ulang lewat query.

**Verifikasi.** 111 test lulus. Sebaran Sumber setelah perbaikan: Pengadaan 15,
Permintaan 30, Manual 15, Saldo Awal 14. Tidak ada lagi `saldo_awal` yang punya
`reference_type`, dan tidak ada `masuk`/`keluar` tanpa `reference_type`.

**Catatan.** Satu kegagalan test transien pernah teramati satu kali dan tidak
dapat direproduksi dalam 4 kali percobaan berikutnya; penyebabnya belum
ditemukan.

---

## 3 September 2026 — Modul Mutasi Stok & Riwayat Mutasi

Dibangun sesuai rancangan low-fi "Riwayat Mutasi", dengan tujuh penyempurnaan:
jumlah bertanda berwarna, kolom Stok Setelah, kolom Petugas, referensi terbaca,
penyaring diperluas, kartu rekap, dan sifat immutable.

**Yang dikerjakan.**

- `MutationType` diperluas dari 4 menjadi 10 case, merangkap alasan mutasi manual
  ([ADR 0006](keputusan/0006-mutationtype-merangkap-alasan-manual.md)).
- Empat kolom audit ditambahkan ke `stock_mutations`: `user_id`, `stock_after`,
  `reference_number`, `notes` — dengan pengisian ulang `stock_after` untuk baris
  lama.
- Formulir mutasi manual dengan enam alasan baku, keterangan wajib, dan penolakan
  jenis milik dokumen.
- Laporan PDF (dompdf), ekspor CSV, dan Kartu Persediaan per barang
  ([ADR 0009](keputusan/0009-laporan-pdf-dan-csv.md)).
- Buku mutasi diurutkan menurut waktu pencatatan, bukan tanggal transaksi
  ([ADR 0005](keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)).

**Temuan penting.** Formulir mengizinkan tanggal mundur, sehingga urutan tanggal
tidak selalu sama dengan urutan pencatatan. Karena `stock_after` adalah saldo
pada saat mutasi dicatat, buku mutasi harus dibaca dalam urutan pencatatan.

**Verifikasi.** 107 test lulus. Uji HTTP nyata: PDF 880 KB diawali `%PDF-1.7`;
mutasi manual menurunkan stok dengan benar; jenis `masuk` ditolak dari formulir
manual; stok kurang memunculkan pesan validasi yang ramah.

**Catatan.** Pint sempat memformat ulang 9 model dan 13 migrasi warisan ERD
karena dijalankan pada direktori terlalu luas — sudah dikembalikan. Untuk
repositori berdua, reformat berkas bersama hanya memicu konflik merge.

---

## 3 September 2026 — Modul Master Barang

Inti sub sistem gudang, karena seluruh modul lain memiliki foreign key ke
`items`.

**Yang dikerjakan.**

- `StockLedger` sebagai satu-satunya jalur perubahan stok, dengan penguncian
  baris dan penolakan stok negatif
  ([ADR 0004](keputusan/0004-stok-hanya-lewat-stockledger.md)).
- Enum `SatuanBarang`, `MutationType`, `StockStatus`.
- CRUD master barang dengan pencarian, tiga penyaring, ringkasan, dan halaman
  detail berisi riwayat mutasi.
- Kolom `is_active` pada `items` — barang bertransaksi diarsipkan, bukan dihapus
  ([ADR 0003](keputusan/0003-arsip-alih-alih-hapus.md)).
- 15 barang ATK/RTK contoh.

**Temuan penting.** Middleware `role:pengelola_gudang` akan **menolak Admin**,
karena `EnsureUserHasRole` memeriksa peran secara harfiah tanpa bypass. Rute
gudang karena itu memakai `can:kelola-gudang`
([ADR 0002](keputusan/0002-otorisasi-peran-gate-dan-can.md)).

**Verifikasi.** 75 test lulus. Uji HTTP: kode barang dinormalkan ke huruf besar;
`stock_current=9999` yang diselundupkan ke request tidak mengubah stok; barang
bermutasi menolak dihapus; `pengadaan` menerima 403, `admin` menerima 200.

---

## 1–2 September 2026 — Manajemen Pengguna

CRUD akun khusus Admin. Karena sistem tertutup tanpa pendaftaran mandiri, modul
inilah satu-satunya cara akun bertambah.

**Yang dikerjakan.**

- CRUD lengkap dengan pencarian, penyaring peran dan status, ringkasan, serta
  pengaktifan/penonaktifan cepat.
- Penjaga: Admin tidak dapat mengubah peran, menonaktifkan, atau menghapus
  akunnya sendiri; akun bertransaksi tidak dapat dihapus.
- Komponen bersama lahir di sini dan dipakai ulang seluruh modul berikutnya:
  `x-form.text-field`, `x-form.select-field`, `x-form.password-field`,
  `x-ui.flash`, dan paginasi bergaya TailAdmin.

**Catatan.** Aturan `alpha_dash` diganti regex sendiri agar pola `budi.santoso`
dapat dipakai. Penjaga "Admin aktif terakhir" ditulis lalu dihapus karena tidak
pernah dapat tereksekusi.

**Verifikasi.** 42 test lulus. Uji HTTP: buat, ubah, toggle, hapus semua bekerja;
`gudang` menerima 403 dan menunya tidak muncul.

---

## 1 September 2026 — Autentikasi & Otorisasi

Fondasi sistem: masuk, keluar, peran, dan penjagaan rute.

**Yang dikerjakan.**

- Autentikasi sesi Laravel tanpa starter kit, disesuaikan dengan skema `users`
  dari ERD ([ADR 0001](keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md)).
- Enum `UserRole` tiga peran, gate berbasis peran dengan `Gate::before` untuk
  Admin, middleware `role:` dan `EnsureUserIsActive`.
- Halaman masuk berbahasa Indonesia tanpa tombol sosial dan tanpa pendaftaran
  mandiri; dasbor sementara dengan modul yang tersaring peran.
- Kolom `last_login_at` dan `last_login_ip`; jejak masuk dan keluar ke
  `audit_logs`.
- Tiga akun awal: `admin`, `gudang`, `pengadaan`.

**Catatan.** Halaman `signin` dan `signup` bawaan template dihapus. Zona waktu
aplikasi disetel `Asia/Jakarta` dan locale `id`.

**Verifikasi.** 20 test lulus. Uji HTTP: login/logout, penguncian setelah lima
percobaan gagal, akun nonaktif ditendang di tengah sesi.
