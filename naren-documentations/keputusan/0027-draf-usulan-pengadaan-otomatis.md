# ADR 0027 — Draf usulan pengadaan otomatis dari pemantauan stok

## Status

Diterima — 13 September 2026

## Konteks

Sebelum keputusan ini, ketika stok sebuah barang turun ke atau di bawah
`stock_min`, satu-satunya hal yang terjadi otomatis adalah notifikasi
`StokMenipis`/`StokHabis` (lihat `App\Support\NotificationScanner`). Tidak ada
usulan pengadaan yang lahir dari sana — hanya sebuah tombol jalan pintas di
halaman detail barang yang mengisikan **satu barang** ke formulir usulan
manual, dengan jumlah yang tetap harus diketik sendiri oleh Pengelola Gudang.

Dalam praktiknya ini merepotkan dengan dua cara. Pertama, Pengelola Gudang
harus mengingat untuk membuka notifikasinya satu per satu dan membuat usulan
sendiri — sistem hanya memberi tahu, tidak menindaklanjuti. Kedua, setiap
barang yang kekurangan menghasilkan satu usulan terpisah, padahal dalam
praktik pengadaan biasanya diajukan sekaligus untuk banyak barang dalam satu
dokumen.

Perubahan ini mengganti mekanisme itu dengan draf yang dijaga otomatis oleh
sistem: seluruh barang yang stoknya di bawah minimum ditampung dalam **satu**
dokumen berstatus `Draf`, lengkap dengan saran jumlah, dan Pengelola Gudang
tinggal meninjau, menyesuaikan, lalu mengajukannya sebagai usulan sungguhan.

## Keputusan

### Satu draf tunggal, dijaga otomatis

`ProcurementRequestStatus` mendapat case baru `Draf`. Kelas baru
`App\Support\ProcurementDraftScanner` — sejajar `NotificationScanner`, dengan
filosofi yang sama: idempoten dan dipanggil eksplisit di setiap titik yang
mengubah stok atau ambang batasnya (lihat [ADR 0014](0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md))
— menjaga satu baris `ProcurementRequest` berstatus `Draf` tetap mencerminkan
barang mana saja yang sedang di bawah minimum:

- Barang yang jatuh di bawah minimum ditambahkan ke draf yang sedang terbuka;
  bila belum ada draf, satu dibuat.
- Barang yang pulih di atas minimum dibuang dari draf — menutup diri sendiri,
  sama seperti notifikasi "keadaan" pada [ADR 0013](0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md).
- Draf yang kehabisan seluruh barangnya **dihapus**, bukan dibiarkan sebagai
  baris kosong — sebuah draf kosong tidak membawa informasi apa pun, dan
  membiarkannya berarti setiap layar yang membaca draf harus menangani kasus
  "ada baris tapi kosong" secara terpisah dari "tidak ada draf".

Jumlah yang disarankan: `max(max(stock_max, stock_min) - stock_current, 1)`.
Ketika `stock_max` diisi dan lebih besar dari `stock_min` (kondisi normal),
ini persis rumus yang diusulkan semula: `stock_max - stock_current`. Ketika
`stock_max = 0` (konvensi "tanpa batas atas" pada `Item::stockStatus()`),
rumus jatuh kembali ke `stock_min` sebagai sasaran, sehingga tidak pernah
menghasilkan saran negatif atau nol untuk barang yang memang belum bertentu
batas atasnya.

### `is_auto`: sekali disentuh manusia, selamanya milik manusia

`procurement_request_details` mendapat kolom `is_auto` (boolean, default
`true`). Selama bernilai `true`, satu baris sepenuhnya dikendalikan
`ProcurementDraftScanner` — jumlahnya dihitung ulang tiap pemindaian, dan
barisnya dibuang begitu barangnya pulih. Begitu Pengelola Gudang menyimpan
perubahan pada draf lewat halaman edit, **seluruh baris yang ia kirim**
ditandai `is_auto = false` dan sejak itu kebal dari perubahan otomatis apa
pun, termasuk penghapusan meski barangnya kelak pulih sendiri.

Ini penanda kasar dan satu arah (tidak ada jalan balik dari manual ke
otomatis), sengaja dibuat sekasar itu untuk tetap konsisten dengan gaya kode
ini di tempat lain — `amount_granted` bernilai null/0/N tanpa kolom status
terpisah, `ProcurementRequestSource` menyatakan asal-usul bukan kepemilikan
per kolom. Konsekuensinya: menyimpan satu perubahan kecil pada satu baris
"mengklaim" seluruh baris yang ikut terkirim pada saat itu, bukan hanya baris
yang benar-benar disentuh. Diterima sebagai kesederhanaan yang sepadan.

### Barang yang dibuang manual bisa muncul lagi

Bila Pengelola Gudang membuang satu baris dari draf padahal barangnya masih
di bawah minimum, pemindaian berikutnya (reaktif dari mutasi lain, atau
sapuan terjadwal) akan memasukkannya kembali sebagai baris otomatis baru.
Ini bukan kegagalan menghormati keputusan pengguna — kekurangan stok barang
itu memang belum selesai, dan satu-satunya cara membuatnya berhenti muncul
adalah menaikkan `stock_min`-nya, memasukkannya ke usulan yang benar-benar
diajukan, atau membuang seluruh draf sekaligus.

### Formulir manual: satu pintu, satu sumber

`StoreProcurementRequestRequest` (formulir usulan manual) tidak lagi menerima
`source = pemantauan_stok`. Jalur itu sekarang seluruhnya milik draf otomatis;
formulir manual hanya untuk usulan yang benar-benar diketik dari nol
(`source = manual`). Jalan pintas lama di halaman detail barang ("Ajukan
Usulan Pengadaan") diarahkan ke halaman draf, bukan lagi ke formulir manual
dengan satu barang terisi.

### Draf tidak terlihat sebagai usulan

`ProcurementRequestStatus::options()` mengecualikan `Draf`, dan
`ProcurementRequestController::index()` menyaring seluruh hitungan dan
daftarnya dengan `bukanDraf()`. Draf ditampilkan lewat banner terpisah di
halaman yang sama, bukan sebagai baris di tabel usulan — sejalan dengan
prinsip [ADR 0026](0026-agregat-tidak-menghitung-realisasi-batal.md): angka
ringkas mencacah dokumen yang benar-benar berstatus usulan, bukan tampungan
sementara yang belum tentu jadi.

### `user_id` boleh kosong sampai diajukan

`procurement_requests.user_id` diubah menjadi nullable. Draf lahir tanpa
pengaju manusia — baik dari pemicu reaktif di controller (ada pengguna yang
sedang login, tetapi ia tidak "mengajukan" apa pun) maupun dari sapuan
terjadwal `notifikasi:periksa` (tidak ada sesi pengguna sama sekali).
`user_id` baru terisi saat draf itu benar-benar diajukan.

### Notifikasi baru: `DraftUsulanTersedia`

Satu jenis notifikasi "keadaan" baru, ditujukan ke Pengelola Gudang dan
Admin, aktif selama draf berisi minimal satu barang dan menutup diri sendiri
begitu draf kosong, diajukan, atau dibuang — meniru pola
`UsulanMenungguPersetujuan`. Tanpa ini, seluruh mekanisme proaktif yang
dibangun di sini kembali menjadi pasif: pengguna tetap harus ingat membuka
halaman usulan sendiri untuk tahu ada draf menunggu.

## Alternatif yang ditolak

**Pemilih saran di dalam formulir manual**, tanpa draf yang dipersist —
formulir "Ajukan Usulan" tetap satu-satunya jalur pembuatan, hanya
ditambahi daftar barang di bawah minimum yang bisa dipilih cepat. Jauh lebih
sederhana: tidak ada status `Draf`, tidak ada kolom `is_auto`, tidak ada
kelas scanner baru. Ditolak atas permintaan eksplisit karena tetap pasif —
Pengelola Gudang masih harus mengingat untuk membuka formulirnya sendiri;
sistem tidak pernah menyodorkan apa pun di depan mereka. Draf otomatis
membalik arah itu: sistem yang menyusun, manusia yang meninjau.

**Pelacakan dirty per kolom** (mis. menandai `amount_request` dan
keberadaan baris secara terpisah) dibanding satu penanda `is_auto` per baris.
Lebih presisi — baris yang jumlahnya diedit tetap bisa dihapus otomatis saat
pulih, sementara baris yang ditambahkan manual tetap istimewa. Ditolak karena
menambah kerumitan yang tidak sepadan; aturan kasar "sekali disimpan manusia,
selamanya milik manusia" lebih mudah dipahami dan diuji, dan sejalan dengan
bagaimana modul ini sudah memperlakukan keputusan serupa di tempat lain.

**Memori "dismiss"** yang mengingat barang yang sengaja dibuang Pengelola
Gudang dari draf, supaya tidak muncul lagi meski masih di bawah minimum.
Ditolak: kekurangan stoknya memang belum selesai, jadi memunculkannya lagi
bukan bug melainkan perilaku yang benar. Menambah memori dismiss hanya akan
menyembunyikan kekurangan stok yang sesungguhnya masih ada.

**Constraint tingkat basis data** (kolom sentinel unik) untuk menjamin
hanya ada satu draf terbuka pada satu waktu. Ditolak sebagai berlebihan untuk
profil pemakaian sistem ini — beberapa staf internal, bukan lalu lintas
tinggi. `lockForUpdate()` pada draf yang sudah ada sudah cukup untuk mencegah
pemindaian ganda saling menimpa; risiko yang tersisa hanyalah dua permintaan
yang benar-benar bersamaan sama-sama mencoba membuat draf **pertama**,
sebuah kasus tepi berpeluang sangat kecil yang diterima apa adanya.

## Konsekuensi

- `App\Support\ProcurementDraftScanner` dipanggil dari titik yang sama dengan
  `NotificationScanner::periksaBarang()` — `ItemController` (store, update,
  toggleStatus), `StockMutationController::store()` — ditambah
  `OpnamePoster::post()` (barang terdampak posting opname) dan
  `notifikasi:periksa` (sapuan terjadwal). Modul baru yang mengubah stok wajib
  memanggilnya juga, persis seperti kewajiban memanggil `NotificationScanner`
  ([ADR 0014](0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md)).
- `ProcurementDraftController::update()` mengganti seluruh rincian draf
  sekaligus pada setiap penyimpanan (baris yang tidak dikirim ulang dianggap
  dibuang) — kebalikan dari konvensi `ProcurementController::update()` untuk
  realisasi, di mana baris yang hilang dari kiriman adalah galat. Keduanya
  sengaja berbeda: satu mengedit dokumen yang sudah final bentuknya, satu lagi
  mengedit tampungan yang bentuknya memang boleh berubah bebas.
- Item detail barang tidak lagi punya jalur pembuatan usulan satu-barang;
  seluruhnya lewat draf atau formulir manual multi-barang.
