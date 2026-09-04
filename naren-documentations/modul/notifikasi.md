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
| Opname Siap Diposting | Perhatian | Pembuat draf | Periksa lalu posting |
| Draf Opname Menggantung | Perhatian | Pembuat + Admin | Lanjutkan atau hapus draf |
| Periode Belum Diopname | Perhatian | Gudang + Admin | Buat draf opname |

### Peristiwa

| Jenis | Tingkat | Penerima | Alasan keberadaannya |
|---|---|---|---|
| Opname Diposting | Info | Gudang + Admin, **kecuali pelaku** | Penyesuaian sudah masuk buku mutasi |
| Stok Bergerak Setelah Dicacah | Perhatian | Pembuat opname | Satu-satunya cara mengetahuinya |
| Mutasi Manual Sensitif | Perhatian | Admin, **kecuali pelaku** | Kontrol internal |

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
| `StockMutationController::store()` | `periksaBarang()` + `catatMutasiManual()` |
| `ItemController::store()` | `periksaBarang()` — barang baru bisa lahir habis |
| `ItemController::update()` | `periksaBarang()` — ambang batas ikut menentukan status |
| `ItemController::toggleStatus()` | `periksaBarang()` — barang diarsipkan menutup peringatannya |
| `ItemController::destroy()` | `Notifier::hapusUntukSubjek()` |
| `OpnamePoster::post()` | `catatOpnameDiposting()`, di luar transaksi |

**Terjadwal**, harian pukul 06.00 lewat `routes/console.php`:

```bash
php artisan notifikasi:periksa              # aman dijalankan kapan saja
php artisan notifikasi:periksa --bersihkan  # sekaligus buang yang lama
```

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
| [app/Enums/NotificationType.php](../../app/Enums/NotificationType.php) | 10 jenis, `bersifatKeadaan()`, `level()`, `dedupKey()` |
| [app/Enums/NotificationLevel.php](../../app/Enums/NotificationLevel.php) | Mendesak / Perhatian / Info, `bobot()`, `dotClass()` |
| [app/Support/Notifier.php](../../app/Support/Notifier.php) | `terapkanKeadaan()`, `catatPeristiwa()`, `selesaikan()`, `bersihkan()` |
| [app/Support/NotificationScanner.php](../../app/Support/NotificationScanner.php) | Seluruh pemeriksaan domain |
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

**Modul Pengadaan** cukup menambah case enum beserta pemeriksaannya. Kandidat
yang sudah terlihat: usulan pengadaan menunggu persetujuan (keadaan), realisasi
berstatus *Menunggu Penerimaan* yang menunggu konfirmasi Pengelola Gudang
(keadaan, sesuai [ADR 0008](../keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md)),
dan penerimaan barang dikonfirmasi (peristiwa). Notifikasi Stok Habis dapat
menautkan langsung ke formulir usulan pengadaan begitu rutenya ada.

**Kanal lain** (surel, WhatsApp) belum dibangun. Titik ekstensinya satu:
`Notifier::tulisSatu()`. Kanal tambahan dikirim dari sana berdasarkan
`NotificationLevel`, tanpa mengubah satu pun pemanggil `NotificationScanner`.
Tabel `notifications` bawaan Laravel sengaja dibiarkan bebas untuk itu.

**Sub sistem permintaan barang** milik Alma belum memanggil scanner setelah
`StockLedger::record()`. Akibatnya terbatas dan sudah diketahui — lihat
konsekuensi pada [ADR 0014](../keputusan/0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md).
