# ADR 0032 — Usulan barang baru: pintu depan katalog

## Status

Diterima — 16 September 2026

## Konteks

Permintaan kedua **Kasubbag Umum dan TI** pada presentasi prototipe yang sama
dengan [ADR 0031](0031-realisasi-pengadaan-tanpa-usulan.md): Pejabat Pengadaan
perlu dapat mengadakan barang yang **belum ada datanya di master barang** —
misalnya spidol warna emas yang belum pernah diadakan. Alur kerja sebenarnya di
lapangan memang begitu: barang baru diadakan lebih dulu, barangnya datang, baru
masuk gudang.

Permintaan ketiga beliau bentuknya berbeda tetapi kebutuhannya sama: pengguna
di sub sistem permintaan barang ingin dapat meminta barang yang belum ada di
sistem.

### Yang tidak mungkin, dan mengapa

Secara teknis barangnya **harus** menjadi baris `items` lebih dulu. Stok tidak
bisa diposting ke sebuah nama bebas: `StockLedger::record()` menuntut sebuah
`Item` ([ADR 0004](0004-stok-hanya-lewat-stockledger.md)),
`procurement_details.item_id` adalah FK NOT NULL, dan `amount_received` harus
mendarat pada satu baris `items` yang nyata
([ADR 0017](0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md)). Tidak ada
jalan pintas di sekitar itu yang tidak merusak buku mutasi.

Sementara itu kode barang, satuan, dan batas minimum/maksimum adalah wewenang
Pengelola Gudang. Dan bagian paling bernilai dari keterlibatannya bukan
pengisian formulir itu, melainkan **pemeriksaan duplikat**: "spidol warna emas"
bisa jadi sudah terdaftar sebagai "Spidol Permanen Emas". Bila setiap orang
dapat mendaftarkan barang sendiri, dalam setahun master barang akan punya tiga
baris untuk satu benda fisik — dan seluruh angka batas minimum, stok opname,
serta kartu persediaannya berhenti bisa dipercaya.

### Mengapa "meminta barang yang tidak ada" bukan sekadar desain jelek

Menjadikan baris `requests` menunjuk barang yang tidak ada — `item_id` nullable
atau nama bebas — memang harus ditolak: itu memutus tautan stok sehingga
permintaannya tak bisa dipenuhi, tak bisa dihitung, dan tak bisa dilayani
`StockLedger`.

Tetapi kebutuhan di baliknya sah, dan pertanyaan "apa bedanya dengan
mulut ke mulut?" punya jawaban: bedanya, **usulan adalah dokumen** — bertuan,
bertanggal, berkeputusan, beralasan, dan terhitung. Percakapan lisan tidak
punya kelimanya. Yang membuat sesuatu terstruktur bukan "barangnya sudah ada di
master", melainkan "permintaannya punya dokumen dan ada pihak yang wajib
menjawabnya". Jadi permintaan lapangan dapat dipenuhi utuh tanpa merusak apa
pun — asalkan disalurkan ke dokumen yang benar.

## Keputusan

### Satu tabel baru: `item_proposals`

Konsepnya: **katalog punya pintu depan.** Satu dokumen berjejak keputusan;
Pejabat Pengadaan lewat pintu itu saat mau mengadakan barang baru, dan pengguna
sub sistem permintaan lewat pintu yang sama kelak saat butuh barang yang belum
ada.

| Kolom | Bentuk |
|---|---|
| `item_proposal_id` | uuid primary |
| `item_name` | string NOT NULL — nama bebas; pengusul belum tentu tahu penamaan bakunya |
| `unit` | string NOT NULL (enum `SatuanBarang`) — pengusul justru tahu satuan barang yang dibutuhkannya |
| `purpose_note` | text NOT NULL — kegunaannya, wajib |
| `proposed_by` | FK `users.user_id`, `restrictOnDelete()` |
| `status` | string, default `diajukan` (enum `ItemProposalStatus`) |
| `item_id` | FK `items.item_id` **nullable**, `restrictOnDelete()` — terisi saat barangnya dibuat |
| `decided_by` | FK `users.user_id` nullable, `restrictOnDelete()` |
| `decided_at` | timestamp nullable |
| `decision_note` | text nullable — wajib saat menolak |

`ItemProposalStatus` hanya tiga case — `Diajukan`, `Disetujui`, `Ditolak` — dan
kedua yang terakhir final. Tidak perlu membedakan "belum berlaku" dari "sudah
berlaku" seperti `ProcurementStatus`, karena tidak ada langkah lanjutan setelah
barangnya terdaftar atau ditolak.

### Menyetujui berarti mendaftarkan barangnya

**Tidak ada rute "setujui".** Persetujuan tanpa baris `items` tidak berarti apa
pun, jadi tombol "Setujui & Buat Barang" pada halaman usulan menuju
`items.create?item_proposal_id=…`:

1. `ItemController::create()` membaca query itu, menolak usulan yang sudah
   diputuskan, lalu menyemai `item_name` dan `unit` dari usulannya. Kode barang
   serta batas minimum/maksimum tetap diisi Pengelola Gudang — hanya dia yang
   mengenal penamaan bakunya.
2. `ItemController::store()` membuat barangnya seperti biasa, lalu **di dalam
   transaksi yang sama** mengisi `item_proposals.item_id`, `status = Disetujui`,
   `decided_by`, dan `decided_at`.

Satu tindakan, satu kebenaran. Karena keduanya satu transaksi, tidak mungkin ada
usulan berstatus `Disetujui` tanpa barang, atau sebaliknya. Polanya sama dengan
ADR 0030: menyimpan perbaikan *adalah* pengajuan ulangnya.

Penjaga "usulan sudah diputuskan" ada di dua tempat — `create()` dan
`StoreItemRequest::withValidator()` — karena formulirnya bisa dikirim ulang dari
tab yang sudah lama terbuka.

### Jalur tolak wajib ada

Pengelola Gudang menolak dengan `decision_note` yang **wajib**. Bukan
formalitas: penolakan yang paling sering terjadi adalah "barangnya sudah ada,
pakai ATK-014", dan tanpa keterangan itu pengusul tidak punya cara mengetahui
barang mana yang dimaksud sehingga akan mengusulkan hal yang sama lagi.

Teks bebas, bukan enum, karena isinya menunjuk barang tertentu — sejajar
`review_note` pada penolakan realisasi (ADR 0030).

Tanpa jalur ini, usulan yang barangnya ternyata sudah terdaftar akan
menggantung selamanya dan tagihan notifikasinya tidak pernah tertutup: jalan
buntu yang sama persis dengan yang diperbaiki
[ADR 0024](0024-pembatalan-realisasi-pengadaan.md).

### Pembagian gate

| Metode | URL | Nama | Gate |
|---|---|---|---|
| GET | `usulan-barang` | `item-proposals.index` | `akses-pengadaan` |
| GET | `usulan-barang/create` | `item-proposals.create` | `kelola-pengadaan` |
| POST | `usulan-barang` | `item-proposals.store` | `kelola-pengadaan` |
| PATCH | `usulan-barang/{itemProposal}/tolak` | `item-proposals.reject` | `kelola-gudang` |
| GET | `usulan-barang/{itemProposal}` | `item-proposals.show` | `akses-pengadaan` |

Segmen statis `create` wajib terdaftar sebelum wildcard `{itemProposal}`, jadi
`create`/`store` masuk grup `can:kelola-pengadaan` yang posisinya di berkas rute
memang sudah berada sebelum grup `can:akses-pengadaan` penutup. Urutan itu
mengikuti file, bukan pengelompokan gate — jebakan yang sudah dua kali mengenai
modul ini.

### Letaknya di folder Gudang

`app/Http/Controllers/Gudang/ItemProposalController.php`, dan form request-nya
di `app/Http/Requests/Gudang/`, walaupun yang berwenang mengajukan justru
pemegang `kelola-pengadaan`. Tiga sebab: subjek dokumennya adalah master
barang, pemutusnya Pengelola Gudang, dan letak itu menjaganya **netral** bagi
pemakaian sub sistem permintaan kelak.

### Tiga notifikasi baru

| Tipe | Sifat | Penerima |
|---|---|---|
| `UsulanBarangMenungguKeputusan` | keadaan | pengawas gudang |
| `UsulanBarangDisetujui` | peristiwa | pengusul |
| `UsulanBarangDitolak` | peristiwa | pengusul, memuat `decision_note` |

`NotificationScanner` mendapat `periksaUsulanBarang()`,
`periksaSeluruhUsulanBarang()` yang disambung ke `periksaSemua()`, dan
`catatUsulanBarangDiputuskan()` — dipanggil eksplisit sesuai
[ADR 0014](0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md).

Perlu diingat `NotificationType::bersifatKeadaan()` adalah **blocklist** dengan
`default => true`, jadi kedua tipe peristiwa harus didaftarkan eksplisit; bila
lupa, keduanya akan dianggap keadaan dan menutup dirinya sendiri.

### Bentuknya netral peran, tetapi pintunya baru satu

Tabelnya sengaja tidak menyimpan asal sub sistem: pengusulnya cukup seorang
`users`, dan perannya sendiri sudah menjelaskan asal usulannya. Dengan begitu
sub sistem permintaan barang dapat memakai tabel yang sama **tanpa perubahan
skema**.

Yang dibangun sekarang hanya pintu Pengadaan dan layar keputusan Gudang. Tidak
ada satu pun berkas sub sistem permintaan yang disentuh. Bila Alma memakainya,
tabelnya berubah sifat menjadi tabel bersama dan perlu disepakati — jadi ini
dikabari, bukan diasumsikan.

## Alternatif yang ditolak

**Nama bebas pada `procurement_details` (atau `requests`), `item_id` nullable.**
Bentuk paling langsung dari permintaan lapangan. Ditolak karena mustahil secara
teknis, bukan karena selera: stok tidak dapat diposting ke sebuah string.
Konsekuensinya bukan sekadar fitur yang pincang — buku mutasi kehilangan
tautannya ke barang, dan `stock_after` berhenti bermakna.

**Kolom `verified_at` pada `items`.** Pejabat Pengadaan membuat baris `items`
tidak lengkap, Pengelola Gudang merapikannya belakangan. Ditolak dua sebab.
Pertama, `items` adalah **tabel bersama dengan Alma**, dan sekarang sudah ada
satu utang persetujuan yang belum tuntas (`kasubbag_umum_ti` pada `users`).
Kedua, jangkauan perubahannya besar: `Item::aktif()` dipakai seluruh dropdown,
snapshot stok opname, `ProcurementDraftScanner`, dan
`NotificationScanner::periksaBarang` — barang stok nol yang belum diverifikasi
akan langsung memicu `StokHabis` mendesak sekaligus tersedot ke draf usulan
otomatis, sehingga enam titik harus diaudit sebelum apa pun bekerja.

**Memberi Pejabat Pengadaan hak membuat barang langsung.** Paling cepat, dan
memang menghapus satu langkah tunggu. Ditolak karena justru menghapus langkah
yang paling bernilai: pemeriksaan duplikat. Master barang yang punya tiga baris
untuk satu benda fisik membuat setiap angka batas minimum, opname, dan kartu
persediaan berhenti bisa dipercaya — kerusakan yang tidak terlihat sampai
terlambat.

**Mengizinkan barang usulan langsung dipakai sebelum Gudang memutuskan.** Baris
`items` lahir seketika dengan kode sementara dan batas nol, lalu dirapikan
belakangan. Ditolak dengan alasan yang sama: pemeriksaan duplikat hilang.
Penundaannya pun hitungan menit karena notifikasinya seketika, dan persetujuan
Kasubbag Umum dan TI toh sudah ada di jalur — satu tunggu singkat bukan
hambatan sebenarnya.

**Memakai `procurement_requests` yang sudah ada** dengan membuat `item_id`-nya
nullable plus kolom nama bebas. Ditolak karena membalik arah dokumennya: usulan
pengadaan dibuat Pengelola Gudang, yang justru tidak perlu mengusulkan barang
kepada dirinya sendiri. Selain itu ia akan menyeret aturan cakupan ADR 0021,
`ProcurementDraftScanner`, dan seluruh kode yang mengandaikan `detail->item`
selalu ada.

**Membangun sekalian pintu untuk sub sistem permintaan.** Ditolak karena di
luar batas kerja: itu wilayah Alma, dan menyentuhnya tanpa kesepakatan justru
melanggar aturan tabel bersama yang sedang dijaga keputusan ini.

## Konsekuensi

**Barang hasil usulan lahir berstok nol, dan itu menyalakan dua hal.**
`StokHabis` (mendesak) langsung berlaku karena `stock_current <= 0`, dan
`ProcurementDraftScanner` menyedotnya ke draf usulan otomatis karena
`diBawahMinimum()` benar untuk `0 <= 0`. Bukan kerusakan — stoknya memang habis
— dan keduanya melepas sendiri begitu barangnya diterima gudang. Dicatat di
sini supaya tidak dikira bug pada sesi berikutnya. Halaman usulan pun
mengatakannya dengan kata-kata.

**Master barang jadi punya kopling kecil ke modul usulan.**
`ItemController::create()` dan `store()` menyadari `item_proposal_id`.
Disengaja: alternatifnya adalah jalur pembuatan barang kedua, yang berarti dua
tempat memutuskan hal yang sama dan cepat atau lambat akan berselisih.

**`User::RESTRICTED_RELATIONS` bertambah tiga relasi.** `itemProposals`
(`proposed_by`) dan `decidedItemProposals` (`decided_by`) dari keputusan ini,
plus `cancelledProcurements` yang **sekalian ditutup** — celahnya ada sejak
ADR 0024 dan tercatat sebagai butir terbuka: FK `cancelled_by` sudah
`restrictOnDelete()` tetapi relasinya luput terdaftar, sehingga menghapus akun
yang pernah membatalkan realisasi meledak jadi 500 alih-alih pesan validasi
[ADR 0003](0003-arsip-alih-alih-hapus.md). `Item::RESTRICTED_RELATIONS` juga
bertambah `itemProposals`.

**Nama barang tidak pernah divalidasi keunikannya pada usulan.** Sengaja:
pemeriksaan duplikat adalah pekerjaan orang yang mengenal penamaan bakunya,
bukan pekerjaan validator yang hanya dapat mencocokkan huruf. "Spidol warna
emas" dan "Spidol Permanen Emas" tidak akan pernah tertangkap aturan `unique`.

**Satu jalur ujung ke ujung terbukti bekerja**, dan itulah skenario yang
diminta: barang belum ada di master → diusulkan Pejabat Pengadaan → didaftarkan
Pengelola Gudang berstok nol → langsung diadakan lewat realisasi tanpa usulan
(ADR 0031) → disetujui Kasubbag Umum dan TI → diterima gudang, stok bertambah.

**Uji baru 23 buah** di `tests/Feature/Gudang/ItemProposalTest.php`, termasuk
matriks hak akses beserta Admin → 200 dan penolakan peran sub sistem
permintaan, pendaftaran barang berstok nol tanpa baris mutasi, penutupan
tagihan keputusan pada kedua jalur, penolakan keputusan ganda, dan jahitan ke
realisasi langsung. Seluruh suite 410 lolos.

### Butir yang perlu dibawa ke lapangan

"Barang yang kosong, tidak ada di sistem" pada permintaan Kasubbag Umum dan TI
sebenarnya **dua hal berbeda** yang mungkin tertukar:

1. barang **ada di katalog tetapi stoknya nol** — ini sudah didukung sejak
   lama, bahkan sudah otomatis menjadi draf usulan (ADR 0027); dan
2. barang **tidak ada di katalog sama sekali** — inilah yang dijawab keputusan
   ini.

Perlu dipastikan yang mana yang dimaksud. Bila (1), sebagian besar
pekerjaannya sudah ada dan yang tersisa hanyalah apakah sub sistem permintaan
mengizinkan permintaan atas barang berstok nol — wilayah Alma, bukan wilayah
ini.
