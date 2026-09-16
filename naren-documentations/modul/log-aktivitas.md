# Modul Log Aktivitas

Jejak audit seluruh aktivitas pengguna di dalam sistem, khusus Admin. Murni
baca: tidak ada rute simpan, ubah, maupun hapus.

## Rute

Seluruhnya di bawah `middleware('role:admin')`.

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/log-aktivitas` | `activity-logs.index` | Daftar + 7 penyaring + 4 rekap |
| GET | `/log-aktivitas/ekspor/csv` | `activity-logs.csv` | Ekspor CSV |

`role:admin` dipakai di sini, bukan `can:` — modul ini hanya Admin, dan itu
preseden grup "Administrasi sistem" yang sudah ada. Aturan `can:` pada
[ADR 0002](../keputusan/0002-otorisasi-peran-gate-dan-can.md) berlaku untuk
rute yang dipakai peran lain *dan juga* Admin.

**Tidak ada rute tulis apa pun.** Baris `audit_logs` bersifat *append-only*,
sejalan dengan buku mutasi pada
[ADR 0005](../keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md).

## Kolom layar

| Kolom | Sumber nilai |
|---|---|
| Waktu | `timestamp`, tanggal dan jam pada zona Asia/Jakarta |
| Pengguna | Nama lengkap dan nama pengguna lewat relasi `user` |
| Peran | `actor_role` — **peran saat aksi dilakukan**, bukan peran sekarang |
| Modul | `module`, enum `AuditModule` |
| Aksi | `event`, enum `AuditEvent` |
| Aktivitas | `activity`, kalimat lengkap; alamat IP tampil kecil di bawahnya |
| Objek | Tautan ke dokumen yang dikenai aksi, bila masih ada |

Diurutkan `timestamp` menurun dengan pemecah seri `log_id`. Pemecah seri itu
perlu: `HasUuids` menghasilkan UUID berurut waktu, sehingga baris yang lahir
pada detik yang sama tetap terurut benar — hal yang tidak dijamin pengurutan
waktu saja.

## Penyaring

| Penyaring | Perilaku |
|---|---|
| Cari | Kata pada `activity`, **atau** pada nama lengkap/nama pengguna pelakunya |
| Pengguna | Satu akun tertentu |
| Peran | Memakai cuplikan `actor_role`, bukan peran pengguna saat ini |
| Modul | Salah satu dari 12 nilai `AuditModule` |
| Aksi | Salah satu dari 14 nilai `AuditEvent` |
| Tanggal Awal / Akhir | Rentang atas `timestamp` |

Ketujuhnya dipakai layar dan ekspor CSV lewat kueri yang sama, sehingga isi
berkas ekspor tidak mungkin berbeda dari yang dilihat pengguna.

## Rekap

Keempat kartunya mengikuti penyaring yang sedang aktif, bukan seluruh tabel:
**Total Aktivitas**, **Pengguna Terlibat** (jumlah akun berbeda), **Aktivitas
Hari Ini**, dan **Perubahan Data**.

Yang terakhir sengaja tidak menghitung `masuk`, `keluar`, dan `unduh` — ketiga
aksi itu penting untuk ditelusuri tetapi tidak mengubah data apa pun, dan
jumlahnya jauh lebih besar daripada yang lain. Bila ikut dihitung, angka yang
justru dicari pemeriksa akan tenggelam. Daftarnya hidup di
`AuditEvent::mengubahData()`.

## Aktivitas yang tercatat

37 titik panggil pada 15 berkas.

| Modul | Aksi yang dicatat |
|---|---|
| Autentikasi | Masuk, keluar |
| Manajemen Pengguna | Tambah, ubah (termasuk penanda ganti kata sandi), ubah status, hapus |
| Master Barang | Tambah, ubah, ubah status, hapus |
| Usulan Barang Baru | Ajukan (dua pintu), setuju (lewat pendaftaran barang), tolak |
| Mutasi Stok | Catat mutasi manual |
| Stok Opname | Buat draf, **ubah hasil cacah**, posting, hapus draf |
| Penyedia Barang | Tambah, ubah, ubah status, hapus |
| Usulan Pengadaan | Ajukan, **ubah draf**, buang draf, setuju, tolak |
| Realisasi Pengadaan | Tambah (termasuk langsung tanpa usulan), ubah, setuju, tolak, batal |
| Penerimaan Barang | Konfirmasi penerimaan |
| Dokumen Pengadaan | Unggah, **unduh**, hapus |

Yang bercetak tebal ditambahkan pada sesi ini; sisanya sudah tercatat sejak
modulnya dibangun.

**Yang sengaja tidak dicatat:** percobaan masuk yang gagal, dan ekspor/cetak
laporan. Keduanya dipertimbangkan lalu ditolak — alasannya di
[ADR 0034](../keputusan/0034-log-aktivitas-terstruktur.md). Unduhan **berkas
pengadaan** tetap dicatat karena itu berkas bukti pada disk privat, bukan
laporan yang isinya sudah terlihat di layar.

## Kolom terstruktur

Kalimat `activity` dipertahankan apa adanya — ia memuat hal yang tidak mungkin
ditampung enum: kode barang, jumlah, alasan pembatalan, nomor dokumen, nama
berkas. Kolom terstrukturnya ada semata-mata supaya log dapat disaring.

`actor_role` adalah **cuplikan**, bukan turunan relasi. Begitu seseorang
dipromosikan, seluruh riwayat lamanya akan ikut terbaca dengan peran barunya
bila nilainya dibaca dari relasi — pemalsuan riwayat tanpa seorang pun
menyentuh satu baris. Lihat
[ADR 0034](../keputusan/0034-log-aktivitas-terstruktur.md).

Tautan objek bergantung pada relasi `subject` yang ter-*resolve*, bukan pada
`subject_id` mentah: baris beraksi `hapus` menunjuk dokumen yang sudah tiada,
dan menautkannya hanya akan mengantar pemeriksa ke halaman 404.

## Ekspor

**CSV** ber-BOM UTF-8 dengan pemisah titik koma, agar terbuka rapi di Excel
lokal. Sepuluh kolom, termasuk **ID Objek** berupa UUID yang tidak pernah
ditampilkan di layar — berkas ini bahan telusur pemeriksa, dan tanpa id
objeknya satu baris log tidak dapat dicocokkan balik ke dokumen sumbernya.

Dibatasi 5.000 baris; melebihi itu ditolak dengan pesan agar rentang tanggalnya
dipersempit lebih dulu.

**Tidak ada ekspor PDF** untuk modul ini — pengguna hanya meminta CSV.

Sel yang diawali `=`, `+`, `-`, `@`, tab, atau CR dibubuhi kutip tunggal
sebelum ditulis. Sebagian kalimat log memuat nama berkas unggahan, satu-satunya
teks di sistem ini yang asalnya dari luar dan tidak dipercaya; nama seperti
`=HYPERLINK("http://...")` akan dijalankan Excel begitu berkasnya dibuka.

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Support/AuditLogger.php](../../app/Support/AuditLogger.php) | Jalur tunggal penulisan jejak audit |
| [app/Enums/AuditModule.php](../../app/Enums/AuditModule.php) | 12 modul, termasuk `permintaan` untuk sub sistem Alma |
| [app/Enums/AuditEvent.php](../../app/Enums/AuditEvent.php) | 14 aksi + `mengubahData()` |
| [app/Models/AuditLog.php](../../app/Models/AuditLog.php) | `scopeFilter()`, `pelaku()`, `alamat()`, `tautanSubjek()`, relasi `subject` |
| [app/Http/Controllers/Admin/ActivityLogController.php](../../app/Http/Controllers/Admin/ActivityLogController.php) | Daftar, rekap, ekspor CSV |
| [resources/views/pages/activity-logs/index.blade.php](../../resources/views/pages/activity-logs/index.blade.php) | Layar tunggal modul ini |
| [app/Providers/AppServiceProvider.php](../../app/Providers/AppServiceProvider.php) | Morph map alias objek |
| [database/seeders/AuditLogSeeder.php](../../database/seeders/AuditLogSeeder.php) | ~120 baris contoh menyebar 30 hari |
| [tests/Feature/Admin/ActivityLogTest.php](../../tests/Feature/Admin/ActivityLogTest.php) | 26 uji |

## Keterbatasan data contoh

Seeder lain menulis model secara langsung tanpa melewati controller, sehingga
tidak satu pun menghasilkan baris `audit_logs`. `AuditLogSeeder` karena itu
menulis barisnya sendiri dan tidak mencerminkan aksi yang benar-benar terjadi
pada data contoh — sebagian barisnya menunjuk dokumen sungguhan, sebagian lagi
menyebut nama akun dan berkas yang tidak pernah ada.
