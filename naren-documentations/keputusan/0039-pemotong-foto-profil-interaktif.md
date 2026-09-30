# ADR 0039 — Pemotong foto profil interaktif, tanpa pustaka

## Status

Diterima — 1 Oktober 2026

Menggantikan sebagian [ADR 0038](0038-profil-pengguna-dan-foto-profil.md):
butir 5 (foto dipotong otomatis dari tengah). Pengolahan tetap di peramban
dengan keluaran yang sama; yang berubah adalah siapa yang menentukan
potongannya.

## Konteks

ADR 0038 memotong foto secara otomatis dari tengah dan menolak pemotong
interaktif (Cropper.js) sebagai dependensi baru untuk sebuah avatar. Setelah
dipakai, pengguna meminta pratinjau dan pemotongan seperti WhatsApp atau
Instagram: potongan tengah sering memenggal wajah pada foto potret, dan
satu-satunya jalan keluar — mengedit foto di aplikasi lain lalu mengunggah
ulang — tidak wajar untuk sebuah foto profil.

## Keputusan

**1. Jendela "Atur foto".** Setelah berkas dipilih, foto tampil di bingkai
persegi dengan topeng lingkaran — persis bentuk avatarnya nanti, sehingga
bingkai itu sekaligus pratinjaunya. Foto digeser dengan seret (tetikus,
sentuhan, pena lewat Pointer Events), diperbesar lewat slider, tombol −/+,
roda tetikus, atau cubitan dua jari, dan digeser dengan tombol panah bagi
pengguna papan ketik. "Simpan" langsung mengunggah; tidak ada langkah
pratinjau kedua.

**2. Ditulis sendiri dengan Alpine, sekitar 150 baris**, tanpa pustaka.
Keadaannya cukup tiga angka — geseran `x`, `y` dari pusat bingkai dan
`zoom` sebagai kelipatan skala "menutup bingkai" — dan dua aturan: foto
tidak boleh menyisakan celah di dalam bingkai, dan perbesaran mengelilingi
pusat bingkai. Perbesaran dibatasi sampai 4× dan sampai potongannya tidak
lebih kecil dari 128 piksel foto asli, supaya hasil 512 px tidak berasal dari
segelintir piksel.

**3. Menu pada avatar**, seperti WhatsApp: mengeklik avatar membuka Lihat
foto / Ganti foto / Hapus foto; bila belum ada foto, langsung membuka pemilih
berkas. Tombol "Ganti foto" dan "Hapus foto" di samping kartu dihapus —
kartunya jadi lebih bersih, sesuai pedoman UI di CLAUDE.md. "Lihat foto"
membuka foto dalam ukuran besar.

## Alternatif yang ditolak

**Cropper.js.** Matang dan kaya fitur, tetapi menambah dependensi beserta
stylesheet-nya, antarmuka bawaannya jauh lebih ramai daripada yang dibutuhkan
avatar, dan tetap perlu dirangkai dengan Alpine. Kebutuhannya — geser,
perbesar, potong persegi — cukup kecil untuk ditulis dan diuji sendiri.

**Tetap potong otomatis, ditambah pratinjau.** Tidak menjawab masalahnya:
pengguna melihat wajahnya terpenggal tanpa dapat berbuat apa-apa.

**Rotasi.** Tidak ditambahkan. Kebutuhan rotasi hampir selalu berasal dari
orientasi EXIF, dan itu sudah diterapkan peramban saat menggambar.

## Konsekuensi

- Hitungan pemotong (batas geser, perbesaran di sekitar pusat, cubitan,
  perubahan ukuran layar, dan koordinat potongan) diuji di Node dengan elemen
  tiruan. Gerakan sungguhan di layar sentuh dan hasil kanvasnya belum
  tercakup uji otomatis mana pun — perlu dicoba manual di ponsel.
- Sisi server tidak berubah sama sekali: berkas yang tiba tetap JPEG 512 px,
  divalidasi aturan yang sama.
