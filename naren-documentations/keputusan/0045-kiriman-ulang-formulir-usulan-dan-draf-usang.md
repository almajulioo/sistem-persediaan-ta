# ADR 0045 — Kiriman ulang formulir usulan dan halaman draf yang usang

## Status

Diterima — 8 November 2026

Sebagian digantikan oleh [ADR 0046](0046-token-kiriman-untuk-semua-formulir-pembuat-dokumen.md):
token tidak lagi disimpan di `procurement_requests.submission_token`, melainkan
di tabel `form_submissions` yang dipakai semua formulir pembuat dokumen.

Menggantikan sebagian [ADR 0027](0027-draf-usulan-pengadaan-otomatis.md):
menyimpan draf tidak lagi membuat draf baru bila draf yang ditampilkan
halaman pengirim sudah tidak ada.

## Konteks

Narendra melaporkan bug di formulir usulan pengadaan manual: setelah
"Usulan #… berhasil diajukan", tombol **Kembali** peramban menampilkan lagi
formulir lengkap dengan daftar barangnya, dan menekan "Ajukan Usulan" lagi
melahirkan usulan baru — tanpa batas. Empat usulan kembar terbentuk dalam satu
percobaan.

Penyebabnya dua lapis:

1. **Peramban memulihkan halaman dari back-forward cache** (bfcache), termasuk
   keadaan Alpine-nya. Laravel mengirim `Cache-Control: no-cache, private`,
   yang tidak menghalangi bfcache.
2. **Server tidak punya cara mengenali kiriman yang sama.** Formulir manual
   tidak membawa apa pun yang mengikatnya ke satu kali tampil, jadi setiap
   POST dianggap usulan baru. Hal yang sama berlaku untuk klik ganda pada
   tombol kirim.

Halaman draf punya celah sejenis dengan bentuk lain. Ketiga aksinya —
simpan, ajukan, buang — bekerja pada "draf apa pun yang ada sekarang",
bukan draf yang ditampilkan. Halaman draf yang usang (tombol Kembali setelah
diajukan, atau tab kedua) karena itu dapat:

- **membuat draf baru** saat "Simpan Perubahan" ditekan, karena
  `update()` membuat draf bila tidak ada — berisi baris lama yang semuanya
  terbaca Manual;
- **mengajukan atau membuang draf baru** yang dibentuk pemindaian stok
  setelah halaman dibuka, padahal belum pernah dilihat siapa pun.

Kode usulan yang tampak sama pada keempat kembaran adalah persoalan terpisah:
`judul()` memakai 8 karakter awal UUID berurut waktu, yang baru berganti
sekitar tiap 65 detik (gotcha yang sudah tercatat). Tidak diubah di sini.

## Keputusan

**1. Token kiriman sekali pakai, dijaga indeks unik.**
`procurement_requests.submission_token` (uuid, nullable, **unique**).
`ProcurementRequestController::create()` membangkitkan token baru setiap kali
formulir tampil; `store()` mewajibkannya dan menyimpannya pada usulan.

- Token yang sudah dipakai → tidak membuat apa pun, tidak mencatat log,
  diarahkan ke usulan yang sudah ada dengan pesan "… sudah diajukan
  sebelumnya."
- Dua kiriman bertoken sama yang tiba **bersamaan** (klik ganda) lolos
  pemeriksaan awal berdua; yang kalah ditolak indeks unik
  (`UniqueConstraintViolationException`) dan diarahkan ke usulan pemenangnya.
- Draf dan usulan lama bernilai NULL; indeks unik mengizinkan NULL berulang.

**2. Aksi draf diikat ke draf yang ditampilkan.** Ketiga formulir halaman
draf membawa `draft_id`. Controller mencari draf dengan id itu, bukan draf
pertama yang ada. Bila tidak cocok:

- draf itu sudah diajukan → diarahkan ke usulannya, "… sudah diajukan
  sebelumnya.";
- selain itu (dibuang, atau digantikan draf baru) → kembali ke halaman draf
  dengan pesan "Draf sudah berubah sejak halaman ini dibuka. Periksa kembali
  isinya."

`update()` tidak lagi membuat draf.

**3. Kenyamanan di peramban: `x-form.sekali-kirim`.** Pembungkus kecil yang
mencatat bahwa salah satu formulirnya terkirim, lalu memuat ulang halaman
bila dipulihkan dari bfcache (`pageshow` dengan `persisted`). Hasilnya,
tombol Kembali memperlihatkan formulir kosong bertoken baru, atau keadaan draf
terbaru. Tombol kirim dinonaktifkan setelah terkirim untuk mencegah klik
ganda. Kiriman yang dibatalkan `confirm()` tidak dihitung.

Lapis 3 hanya kenyamanan; jaminannya lapis 1 dan 2 di server.

## Alternatif yang ditolak

**Mencegah bfcache dengan `Cache-Control: no-store`.** Menyelesaikan kasus
tombol Kembali tetapi tidak klik ganda, dan mematikan bfcache untuk halaman
yang justru diuntungkan olehnya. Penjaganya tetap harus di server.

**Token di cache atau sesi.** `Cache::add()` atomik pada driver `database`,
tetapi tokennya hilang saat cache dibersihkan (`optimize:clear` ketika
deploy), dan sesi tidak atomik untuk dua permintaan bersamaan. Indeks unik
pada dokumennya sendiri tidak punya kedua kelemahan itu, sejalan dengan
kebiasaan proyek menyerahkan invarian ke basis data (ADR 0024, ADR 0031).

**Menolak usulan yang isinya sama persis dalam beberapa menit.** Menebak
niat dari isi: dua usulan sungguhan bisa saja identik, dan kiriman ulang
dengan isi yang sedikit diubah lolos.

**Mengirim ulang ke halaman galat alih-alih ke usulan yang ada.** Pengguna
yang menekan Kembali tidak berbuat salah; yang ia perlukan adalah dokumen
yang sudah terbentuk.

**`draft_id` opsional demi menghemat perubahan test.** Klien tanpa `draft_id`
justru yang harus ditolak; satu-satunya klien adalah halaman draf itu
sendiri, yang selalu mengirimnya.

## Konsekuensi

- Formulir pembuat dokumen lain yang **tidak punya penjaga keunikan apa pun**
  masih terbuka terhadap pola yang sama: mutasi stok manual (paling
  berbahaya — buku mutasi immutable, kembarannya hanya dapat dikoreksi dengan
  mutasi lawan), realisasi tanpa usulan, usulan barang baru, unggah berkas
  pengadaan, dan stok opname. Penyedia, barang, dan pengguna terlindung aturan
  `unique` pada nama/kode/username. Polanya kini tersedia untuk dipakai ulang:
  kolom token unik + `x-form.sekali-kirim`.
- `PermohonanTest` (milik Alma) dan `ActivityLogTest` ikut mengirim
  `draft_id`.
- Kode usulan yang sama untuk dokumen berbeda (`judul()`) tetap terjadi bila
  dua usulan lahir dalam rentang ~65 detik; perlu keputusan tersendiri.
