# ADR 0038 — Profil pengguna: swalayan terbatas dan foto profil

## Status

Diterima — 1 Oktober 2026

Sebagian digantikan oleh [ADR 0039](0039-pemotong-foto-profil-interaktif.md):
butir 5 — foto kini dipotong lewat pemotong interaktif, bukan otomatis dari
tengah.

## Konteks

Menu "Profil Saya" sudah berdiri di sidebar dan di dropdown header sejak
awal, tetapi rute `/profil` hanya merender halaman contoh bawaan TailAdmin:
nama palsu, tautan media sosial, dan kartu alamat — data yang tidak ada di
tabel `users` sama sekali.

Akibatnya lebih dari sekadar halaman kosong. **Tidak ada satu pun pengguna yang
dapat mengganti kata sandinya sendiri.** Satu-satunya jalur adalah Admin lewat
Manajemen Pengguna, sehingga kata sandi awal yang dibuat Admin — dan karena itu
diketahui Admin — tidak pernah berganti. Untuk sistem di lembaga pemeriksa
keuangan yang menjadikan jejak audit sebagai jaminan "siapa melakukan apa",
kata sandi yang diketahui dua orang merusak jaminan itu dari akarnya.

Permintaan pengguna: halaman profil yang fungsional, menampilkan data akun dari
tabel `users`, memungkinkan pemilik akun mengganti sebagian data pribadinya
(kata sandi disebut eksplisit), serta foto profil yang diunggah masing-masing
dengan gambar bawaan bagi yang belum mengunggah. Pengguna juga meminta
pendapat: data mana yang pantas diubah sendiri.

Rencana diajukan sebelum membangun, dengan empat pertanyaan keputusan.
Pengguna memilih seluruh rekomendasi, dan dari tambahan opsional hanya
memilih moderasi foto oleh Admin.

Tiga temuan dari kode yang ikut menentukan jawabannya:

1. `fullname` **dicetak di blok tanda tangan** BAST Pengadaan, BAST dan Nota
   Dinas Permintaan, serta Laporan Opname — dan PDF dirender ulang dari data
   terkini setiap kali dicetak.
2. **Email adalah pengenal masuk**, bukan sekadar data kontak: `LoginRequest`
   menerima nama pengguna *atau* email.
3. Ekstensi `exif` PHP **tidak aktif** di mesin pengembangan, sementara GD
   aktif. Foto ponsel lazimnya menyimpan orientasinya sebagai tanda EXIF, bukan
   dengan memutar pikselnya.

## Keputusan

**1. Pembagian wewenang atas data akun.** Prinsipnya: data yang menentukan
*siapa Anda di sistem dan apa wewenang Anda* dikelola Admin; data yang murni
milik pribadi dikelola sendiri.

| Kolom | Siapa yang mengubah | Alasan |
|---|---|---|
| `password` | Pemilik, wajib kata sandi saat ini | Hanya pemilik yang boleh mengetahuinya |
| `email` | Pemilik, wajib kata sandi saat ini | Data kontak, tetapi juga pengenal masuk — sesi yang ditinggal terbuka tidak boleh cukup untuk menggantinya |
| `avatar_path` | Pemilik; Admin hanya dapat menghapus | Preferensi pribadi; Admin memoderasi |
| `fullname` | Admin | Identitas resmi yang tercetak di tanda tangan dokumen |
| `username` | Admin | Pengenal masuk, dan terbekukan dalam kalimat `audit_logs.activity` |
| `role`, `unit`, `is_active` | Admin | Menentukan otorisasi |

Kolom milik Admin tetap **ditampilkan** di halaman profil sebagai data baca
saja, dengan keterangan bahwa perubahannya melalui Admin.

Form request profil hanya memuat aturan untuk kolom yang boleh diubah, sehingga
isian selundupan (`fullname`, `role`, `avatar_path`, …) terbuang oleh
`validated()`. `avatar_path` sengaja **tidak** masuk `$fillable` dan hanya
diisi lewat `forceFill()` oleh controller foto.

**2. Kata sandi.** Kebijakannya sama persis dengan pembuatan akun oleh Admin —
`Password::defaults()` — ditambah `different:current_password`. Sesi di
perangkat lain keluar dengan sendirinya: `AuthenticateSession` (sudah aktif
sejak ADR 0001) membandingkan hash kata sandi yang tersimpan di tiap sesi
dengan hash terkini, lalu menyimpan hash baru ke sesi yang sedang dipakai
*setelah* request selesai, sehingga hanya sesi itu yang bertahan. ID sesinya
diganti (`regenerate()`) setelah kredensial berubah. Daftar syarat kata sandi
di formulir dibaca dari `Password::defaults()->appliedRules()`, bukan ditulis
ulang, supaya tidak tertinggal bila kebijakannya berubah.

**3. Foto profil disimpan di kolom baru `users.avatar_path`** (string,
nullable, aditif). `users` adalah tabel bersama, jadi kolom ini **menunggu
persetujuan Alma** — pola yang sama dengan penambahan kolom `audit_logs` pada
ADR 0034.

**4. Diskinya privat**, sama dengan berkas pengadaan (ADR 0018): folder
`avatars/` di `storage/app/private`, nama berkas UUID, disajikan lewat rute
terautentikasi `GET /profil/foto/{user}`. Siapa pun yang **sudah masuk** boleh
melihat foto siapa pun — foto tampil di daftar pengguna milik Admin dan di
tempat lain yang dilihat orang lain; yang dijaga hanya dari tamu. Respons
membawa `nosniff` dan `Cache-Control: private, max-age=604800`; URL-nya memuat
parameter `v` yang diturunkan dari path berkas, sehingga berganti pada setiap
unggahan dan cache yang lama tidak pernah menampilkan foto yang sudah diganti.
Tanpa cache, foto di header diminta ulang pada setiap halaman.

**5. Foto diolah di peramban sebelum dikirim.** Alpine menggambar foto ke
`<canvas>`, memotong persegi dari tengah, memperkecilnya ke 512 px, lalu
menyimpannya ulang sebagai JPEG dan menukar berkas pada `<input>` lewat
`DataTransfer`. Tiga manfaat sekaligus:

- Orientasi EXIF diterapkan peramban saat menggambar, sehingga foto potret dari
  ponsel tidak tampil miring.
- Metadata terbuang, termasuk **lokasi GPS** yang lazim ditanam kamera ponsel.
- Hasilnya sekitar 100 KB, jauh di bawah `upload_max_filesize` bawaan PHP
  (2 MB) — gotcha yang sudah menggigit foto belanja pada ADR 0035.

Server tetap memvalidasi penuh sebagai jaring pengaman bila pengolahan di
peramban gagal: `image`, `mimes:jpg,jpeg,png,webp`, `max:2048`, minimal
96×96 piksel. HEIC sengaja tidak diterima, berbeda dari foto belanja: foto
profil tampil di setiap halaman, dan hanya Safari yang mampu menampilkan HEIC.

**6. Gambar bawaan adalah avatar inisial** yang sudah dipakai sejak modul
Manajemen Pengguna (`User::initials()`). Komponen baru `x-ui.user-avatar`
menampilkan foto bila ada dan inisial bila tidak, menggantikan empat tempat
yang sebelumnya menulis lingkaran inisialnya sendiri: dropdown header, dasbor,
daftar pengguna, dan halaman Ubah Pengguna. Bila baris menunjuk foto yang
berkasnya hilang, atribut `onerror` bawaan menukarnya ke inisial — sengaja
bukan Alpine, karena galat gambar dapat terjadi sebelum Alpine selesai dimuat.

**7. Moderasi oleh Admin.** `DELETE /pengguna/{user}/foto` pada halaman Ubah
Pengguna. Admin hanya dapat menghapus, tidak mengunggah — foto adalah pilihan
pemilik akun. Menghapus akun ikut membuang berkas fotonya.

**8. Jejak audit.** Kasus baru `AuditModule::Profil` untuk aksi pemilik akun
atas akunnya sendiri: mengganti email (email lama ikut dicatat, karena ia
pengenal masuk), mengganti kata sandi, mengunggah dan menghapus foto. Moderasi
Admin tetap tercatat di modul `Pengguna`. Melihat foto tidak dicatat.

## Alternatif yang ditolak

**Nama lengkap dapat diubah sendiri.** Mengurangi beban Admin untuk koreksi
gelar atau salah ketik, dan perubahannya tetap terekam di log. Ditolak karena
nama itu tercetak di blok tanda tangan dokumen resmi, dan PDF dirender ulang
dari data terkini: pemilik akun dapat mengubah nama yang tampil di bawah tanda
tangannya — termasuk pada dokumen lama yang dicetak ulang — tanpa melewati
siapa pun. Nama di tanda tangan adalah data kepegawaian, bukan preferensi.

**Foto tanpa kolom baru, di path tetap `avatars/{user_id}.jpg`.** Tabel
`users` tidak tersentuh, sehingga tidak perlu persetujuan Alma. Ditolak:
keberadaan foto harus ditanyakan ke disk pada setiap render (sepuluh kali per
halaman daftar pengguna), cache peramban butuh waktu ubah berkas sebagai
penanda versi, dan basis data tidak lagi menjadi sumber kebenaran.

**Disk publik dengan `storage:link`.** Paling sederhana dan tanpa rute
tambahan. Ditolak: foto wajah pegawai lembaga pemeriksa keuangan dapat
diunduh siapa pun yang menebak URL-nya, dan server perlu langkah pemasangan
tambahan yang selama ini sengaja dihindari (ADR 0018).

**Memotong dan menyimpan ulang di server dengan GD.** Tidak bergantung pada
peramban. Ditolak: GD mengabaikan orientasi EXIF, dan membacanya butuh
ekstensi `exif` yang tidak aktif, sehingga foto potret dari ponsel akan
tersimpan miring. Unggahan juga tetap berukuran asli dan terbentur batas 2 MB.

**Pemotong interaktif (Cropper.js).** Pengguna dapat memilih bagian foto yang
dipotong. Ditolak karena menambah dependensi untuk sebuah avatar yang tampil
selebar 44 piksel; potongan tengah dengan pratinjau sebelum disimpan sudah
cukup, dan pengguna dapat mengulang dengan foto lain.

**Siluet generik sebagai gambar bawaan.** Sesuai permintaan harfiah, tetapi
semua akun tanpa foto tampak identik di daftar pengguna. Inisial tetap
membedakan tiap orang, dan sudah menjadi bahasa visual sistem ini.

## Konsekuensi

- **Butir terbuka baru: Alma perlu menyetujui `users.avatar_path`.** Kolomnya
  aditif dan nullable; tidak ada kode Alma yang disentuh, dan kode yang tidak
  mengenal kolom ini tetap berjalan.
- Profil berlaku untuk **seluruh peran**, termasuk `kepala_unit` dan
  `pengguna` milik sub sistem Permintaan — halamannya memang lintas sub sistem.
- Pengguna yang menghendaki koreksi nama tetap bergantung pada Admin.
- Pengolahan foto bergantung pada peramban modern (orientasi EXIF pada kanvas
  dan `DataTransfer` tersedia sejak 2020). Pada peramban yang lebih tua, berkas
  asli yang dikirim dan validasi server yang menjaga; orientasinya bisa keliru.
- Logika kanvas tidak tercakup uji Pest — uji fitur hanya melihat berkas yang
  tiba di server. Sintaks dan logika syarat kata sandinya diperiksa dengan Node;
  unggah foto ponsel sungguhan perlu dicoba manual di peramban.
- Foto tidak ikut dicadangkan bersama basis data. Bila basis data dipulihkan
  tanpa folder storage, baris yang menunjuk foto yang hilang tampil sebagai
  inisial, bukan gambar rusak.
