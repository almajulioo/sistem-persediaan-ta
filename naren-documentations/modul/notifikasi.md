# Modul Notifikasi

Notifikasi dalam aplikasi: lonceng pada header di seluruh halaman, dan halaman
daftar tersendiri. Melayani seluruh peran, dan dirancang agar modul berikutnya
cukup menambah satu case enum untuk ikut memakainya.

## Prinsip: keadaan vs peristiwa

Ini inti rancangannya, dan yang membedakannya dari kotak masuk biasa.

| | **Keadaan** | **Peristiwa** |
|---|---|---|
| Contoh | Stok kertas habis | Opname triwulan III diposting |
| Sifat | Sedang benar sekarang | Fakta historis |
| Menutup sendiri | Ya, saat kondisinya pulih | Tidak pernah |
| Hilang dari lonceng | Saat pulih | Saat dibaca |
| Kolom penentu | `resolved_at` terisi | `resolved_at` selamanya null |

Sifat melekat pada jenisnya lewat `NotificationType::bersifatKeadaan()`. Lihat
[ADR 0013](../keputusan/0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md)
untuk alasan lengkapnya, termasuk dua jalan buntu yang dihindari: banjir
notifikasi bila keadaan diperlakukan sebagai peristiwa, dan kabar basi yang
menyesatkan bila keadaan tidak pernah ditutup.

## Katalog notifikasi

Tiap jenis lolos dua ujian: **penerimanya belum tahu**, dan **ada yang bisa
dilakukan**. Yang gagal salah satunya tidak dibuat — notifikasi "Anda mencatat
mutasi" gagal ujian pertama, "akun Anda dinonaktifkan" gagal ujian kedua karena
penerimanya sudah ditendang keluar sesi.

### Keadaan

| Jenis | Tingkat | Penerima | Tindak lanjut |
|---|---|---|---|
| Stok Habis | Mendesak | Gudang + Admin | Usul pengadaan, atau arsipkan |
| Stok Menipis | Perhatian | Gudang + Admin | Usul pengadaan sebelum habis |
| Stok Berlebih | Info | Gudang + Admin | Tinjau kebutuhan, cegah menumpuk |
| Barang Tidak Bergerak | Info | Gudang + Admin | Evaluasi atau arsipkan |
| Draf Usulan Tersedia | Perhatian | Gudang + Admin | Tinjau, sesuaikan jumlah, lalu ajukan |
| Opname Siap Diposting | Perhatian | Pembuat draf | Periksa lalu posting |
| Draf Opname Menggantung | Perhatian | Pembuat + Admin | Lanjutkan atau hapus draf |
| Periode Belum Diopname | Perhatian | Gudang + Admin | Buat draf opname |
| Usulan Menunggu Persetujuan | Perhatian | Pejabat Pengadaan + Admin | Setujui atau tolak |
| Usulan Pengadaan Menggantung | Perhatian | Pejabat Pengadaan + Admin + pengaju | Segera putuskan, sudah &gt;7 hari |
| Usulan Disetujui Belum Direalisasi | Perhatian | Pejabat Pengadaan + Admin | Tekan Realisasikan, atau tinjau ulang kebutuhannya |
| Realisasi Menunggu Penerimaan | Perhatian | Gudang + Admin | Periksa fisik barang, konfirmasi penerimaan |
| BAST Belum Diunggah | Perhatian | Gudang + Admin | Cetak BAST, mintakan tanda tangan, unggah pindaiannya |

### Peristiwa

| Jenis | Tingkat | Penerima | Alasan keberadaannya |
|---|---|---|---|
| Opname Diposting | Info | Gudang + Admin, **kecuali pelaku** | Penyesuaian sudah masuk buku mutasi |
| Stok Bergerak Setelah Dicacah | Perhatian | Pembuat opname | Satu-satunya cara mengetahuinya |
| Mutasi Manual Sensitif | Perhatian | Admin, **kecuali pelaku** | Kontrol internal |
| Usulan Pengadaan Disetujui | Info | Pengaju | Tahu usulannya lanjut ke realisasi |
| Usulan Pengadaan Ditolak | Perhatian | Pengaju | Tahu perlu revisi atau usulan baru |
| Penerimaan Barang Dikonfirmasi | Info | Pejabat Pengadaan yang merealisasi | Tahu barang yang direalisasikannya sudah sampai |
| Realisasi Pengadaan Dibatalkan | Perhatian | Gudang + Admin, **kecuali pelaku** | Sudah disuruh menunggu barang; harus tahu barangnya tidak jadi datang |

**Mutasi Manual Sensitif** mencakup Barang Rusak, Barang Hilang, Kadaluarsa, dan
Koreksi Pencatatan — jenis yang mengurangi stok **tanpa dokumen sumber**. Pada
instansi pemeriksa, justru inilah yang paling layak diketahui Admin.

**Stok Bergerak Setelah Dicacah** memakai `OpnameDetail::stokBerubahSejakDicacah()`
yang sudah ada sejak modul opname tetapi **tidak pernah ditampilkan kepada
siapa pun**. Lihat [ADR 0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md).

## Ambang batas

| Tetapan | Nilai | Berkas |
|---|---|---|
| Draf opname menggantung | 7 hari | `NotificationScanner::HARI_DRAF_MENGGANTUNG` |
| Barang tidak bergerak | 180 hari | `NotificationScanner::HARI_TIDAK_BERGERAK` |
| Jelang akhir periode | 30 hari | `NotificationScanner::HARI_JELANG_AKHIR_PERIODE` |
| Usulan pengadaan menggantung | 7 hari | `NotificationScanner::HARI_USULAN_MENGGANTUNG` |
| Usulan disetujui belum direalisasi | 7 hari | `NotificationScanner::HARI_USULAN_BELUM_DIREALISASI` |
| BAST belum diunggah | 3 hari | `NotificationScanner::HARI_BAST_BELUM_DIUNGGAH` |
| Simpan notifikasi selesai | 30 hari | `Notifier::HARI_SIMPAN` |

Ambang stok memakai `items.stock_min` dan `items.stock_max` yang sudah ada sejak
modul master barang — tidak ada kolom ambang baru.

## Rute

Di dalam grup `middleware('auth')`, **tanpa gate peran**: setiap pengguna berhak
atas notifikasinya sendiri. Yang dijaga adalah kepemilikan tiap baris.

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/notifikasi` | `notifications.index` | Daftar + 3 penyaring + rekap |
| PATCH | `/notifikasi/{notification}/baca` | `notifications.read` | Tandai dibaca, antar ke tujuan |
| POST | `/notifikasi/baca-semua` | `notifications.read-all` | Tandai seluruhnya dibaca |

Membaca notifikasi mengubah keadaan, karena itu dikirim lewat PATCH ber-CSRF,
bukan tautan biasa. Notifikasi milik orang lain menghasilkan **404, bukan 403** —
keberadaannya pun tidak perlu dikonfirmasi.

`action_url` selalu ditulis sistem sebagai lintasan relatif, tetapi tetap
diperiksa sebelum dipakai sebagai tujuan pengalihan.

## Idempotensi

Seluruh method `periksa*` menyatakan **keadaan apa yang sedang benar**, bukan
"tambahkan satu baris". Menjalankannya sepuluh kali sama hasilnya dengan sekali.

Penopangnya indeks unik `(user_id, dedup_key)` pada `user_notifications`, dengan
`dedup_key` berpola `{jenis}:{subjek}`, misalnya `stok_habis:{item_id}`.

Karena itu **pemeriksaan reaktif hanyalah percepatan, bukan syarat kebenaran**.
Jalur perubahan stok yang lupa memanggilnya tetap tertangkap pemeriksaan
terjadwal; yang hilang hanya kesegeraan.

Keadaan yang **kambuh** memakai ulang baris yang sama — `resolved_at` dan
`read_at` dikosongkan, `created_at` disetel ulang — sehingga barang yang stoknya
bergoyang di sekitar batas minimum tidak menumpuk puluhan baris.

## Pemicu

**Reaktif**, dipanggil eksplisit seperti `AuditLogger`
([ADR 0014](../keputusan/0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md)):

| Titik panggil | Pemeriksaan |
|---|---|
| `StockMutationController::store()` | `periksaBarang()` + `catatMutasiManual()` + `ProcurementDraftScanner::perbaruiBarang()` |
| `ItemController::store()` | `periksaBarang()` + `ProcurementDraftScanner::perbaruiBarang()` — barang baru bisa lahir habis |
| `ItemController::update()` | `periksaBarang()` + `ProcurementDraftScanner::perbaruiBarang()` — ambang batas ikut menentukan status |
| `ItemController::toggleStatus()` | `periksaBarang()` + `ProcurementDraftScanner::perbaruiBarang()` — barang diarsipkan menutup peringatan dan baris drafnya |
| `ItemController::destroy()` | `Notifier::hapusUntukSubjek()` |
| `OpnamePoster::post()` | `catatOpnameDiposting()` + `ProcurementDraftScanner::perbaruiBarangTerdampak()`, di luar transaksi |
| `ProcurementController::store()` | `periksaRealisasi()` + `periksaUsulan()` — usulannya berhenti menganggur |
| `ProcurementController::cancel()` | `catatRealisasiDibatalkan()`, yang ikut menutup keadaan menunggu penerimaan |
| `ProcurementReceiptPoster::post()` | `catatPenerimaanDikonfirmasi()`, di luar transaksi |
| `ProcurementDocumentController::store()` | `periksaRealisasi()` — berkas BAST menutup keadaannya |
| `ProcurementDocumentController::destroy()` | `periksaRealisasi()` — keadaannya berlaku lagi |

**Terjadwal**, harian pukul 06.00 lewat `routes/console.php`:

```bash
php artisan notifikasi:periksa              # aman dijalankan kapan saja
php artisan notifikasi:periksa --bersihkan  # sekaligus buang yang lama
```

Sejak [ADR 0027](../keputusan/0027-draf-usulan-pengadaan-otomatis.md), perintah
yang sama juga menjalankan `App\Support\ProcurementDraftScanner::perbaruiSeluruh()`
— sapuan penuh draf usulan otomatis, sejajar `NotificationScanner::periksaSemua()`.

Di server dibutuhkan satu entri cron:

```
* * * * * cd /path/proyek && php artisan schedule:run >> /dev/null 2>&1
```

Tanpa cron, notifikasi stok tetap muncul lewat pemicu reaktif; yang tertunda
hanya yang berbasis waktu — periode jatuh tempo, draf menggantung, barang tidak
bergerak.

## Antarmuka

**Lonceng** di header seluruh halaman. Badge berisi **angka** jumlah yang belum
dibaca, berwarna merah bila ada yang mendesak. Isinya dibagi dua bagian:
"Perlu Tindakan" (keadaan) dan "Aktivitas Terbaru" (peristiwa), masing-masing
maksimal 5 baris. Datanya diambil komponen `NotificationDropdown` sendiri, bukan
disuntikkan tiap controller, sehingga tidak ada halaman yang bisa lupa
menyediakannya — biayanya dua kueri ringan per permintaan.

**Halaman `/notifikasi`** menyajikan daftar, bukan tabel: isi notifikasi berupa
kalimat utuh yang tidak terbaca dalam sel sempit. Penyaring jenis, tingkat, dan
status; kartu rekap Mendesak / Perlu Perhatian / Belum Dibaca / Total Aktif.
Baris yang sudah pulih tetap ditampilkan dengan penanda "Sudah pulih".

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Enums/NotificationType.php](../../app/Enums/NotificationType.php) | 25 jenis, `bersifatKeadaan()`, `level()`, `dedupKey()` |
| [app/Enums/NotificationLevel.php](../../app/Enums/NotificationLevel.php) | Mendesak / Perhatian / Info, `bobot()`, `dotClass()` |
| [app/Support/Notifier.php](../../app/Support/Notifier.php) | `terapkanKeadaan()`, `catatPeristiwa()`, `selesaikan()`, `bersihkan()` |
| [app/Support/NotificationScanner.php](../../app/Support/NotificationScanner.php) | Seluruh pemeriksaan domain |
| [app/Support/ProcurementDraftScanner.php](../../app/Support/ProcurementDraftScanner.php) | Sejajar NotificationScanner, tetapi menjaga draf usulan pengadaan (ADR 0027), bukan baris notifikasi |
| [app/Models/Notification.php](../../app/Models/Notification.php) | Tabel `user_notifications`, scope `aktif()`, `terpenting()` |
| [app/Console/Commands/PeriksaNotifikasi.php](../../app/Console/Commands/PeriksaNotifikasi.php) | `notifikasi:periksa` |
| [app/Http/Controllers/NotificationController.php](../../app/Http/Controllers/NotificationController.php) | Daftar, tandai dibaca |
| [app/View/Components/header/NotificationDropdown.php](../../app/View/Components/header/NotificationDropdown.php) | Data lonceng |
| [resources/views/components/header/notification-dropdown.blade.php](../../resources/views/components/header/notification-dropdown.blade.php) | Lonceng |
| [resources/views/pages/notifications/index.blade.php](../../resources/views/pages/notifications/index.blade.php) | Halaman daftar |
| [tests/Feature/NotificationTest.php](../../tests/Feature/NotificationTest.php) | 19 uji |

## Catatan penamaan

Tabelnya `user_notifications`, **bukan** `notifications`. Model `User` memakai
trait `Notifiable` bawaan Laravel yang mengklaim nama itu beserta bentuk
kolomnya sendiri; memakainya untuk skema berbeda akan membuat
`$user->notifications` meledak. Nama ini juga menyisakan tabel bawaan tetap
bebas seandainya notifikasi surel kelak dibangun di atas mekanisme Laravel.

## Rencana lanjutan

**Tahap 2 dan 3 Pengadaan (usulan, realisasi, penerimaan) sudah dibangun** —
lihat katalog di atas,
[ADR 0020](../keputusan/0020-notifikasi-usulan-dan-persetujuan-sebagian.md),
dan [ADR 0021](../keputusan/0021-realisasi-dan-penerimaan-pengadaan.md).
Tombol "Lihat Draf Usulan Pengadaan" ada di halaman barang yang stoknya
Habis/Menipis (mengarah ke draf otomatis sejak
[ADR 0027](../keputusan/0027-draf-usulan-pengadaan-otomatis.md), bukan lagi
formulir manual dengan satu barang terisi), "Realisasikan" ada di halaman
usulan yang sudah disetujui, dan "Konfirmasi Penerimaan" ada di halaman
realisasi yang masih Menunggu Penerimaan — notifikasi keadaannya sendiri
menautkan ke halaman dokumen yang bersangkutan, bukan langsung membuka
formulir aksi.

**Tahap 4 Pengadaan sudah dibangun**, beserta `BastBelumDiunggah` yang
dijanjikan [ADR 0018](../keputusan/0018-berkas-unggahan-di-disk-privat.md):
penerimaan yang sudah dikonfirmasi lebih dari tiga hari lalu tetapi berkas BAST
bertanda tangannya belum diunggah. Keadaannya menutup diri sendiri begitu
berkasnya masuk, dan berlaku lagi bila berkas itu dihapus. Ambangnya sengaja
tidak nol: urutan fisiknya cetak &rarr; tanda tangan &rarr; pindai &rarr;
unggah, dan yang ditagih adalah kelalaian, bukan jeda yang normal.

**Seluruh perpindahan tangan kini punya penagihnya.** Sejak
[ADR 0024](../keputusan/0024-pembatalan-realisasi-pengadaan.md), rantai
pengadaan tertutup penuh: usulan&rarr;keputusan dijaga `UsulanMenungguPersetujuan`
dan `UsulanMenggantung`, keputusan&rarr;realisasi oleh `UsulanBelumDirealisasi`,
realisasi&rarr;penerimaan oleh `RealisasiMenungguPenerimaan`, dan
penerimaan&rarr;berkas oleh `BastBelumDiunggah`. Jarak antara "disetujui" dan
"direalisasi" adalah yang terakhir ditutup — dulu satu-satunya yang sunyi,
padahal justru di situ barang yang sudah disetujui dapat terlupakan tanpa jejak.

**Kanal lain** (surel, WhatsApp) belum dibangun. Titik ekstensinya satu:
`Notifier::tulisSatu()`. Kanal tambahan dikirim dari sana berdasarkan
`NotificationLevel`, tanpa mengubah satu pun pemanggil `NotificationScanner`.
Tabel `notifications` bawaan Laravel sengaja dibiarkan bebas untuk itu.

**Sub sistem permintaan barang** milik Alma belum memanggil scanner setelah
`StockLedger::record()`. Akibatnya terbatas dan sudah diketahui — lihat
konsekuensi pada [ADR 0014](../keputusan/0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md).
