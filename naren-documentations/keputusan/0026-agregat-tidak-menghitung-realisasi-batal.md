# ADR 0026 — Agregat mencacah pengadaan yang terjadi, bukan dokumen yang pernah dibuat

## Status

Diterima — 7 September 2026

## Konteks

Laporan dari pemakaian nyata: setelah sebuah realisasi dibatalkan, kolom
**"Pengadaan"** pada daftar penyedia tetap bertambah satu. Penyedia yang seluruh
pengadaannya batal tetap tertulis sudah melayani sekian kali pengadaan.

Penelusuran menunjukkan penyebabnya satu baris —
`SupplierController::index()` memakai `withCount('procurements')`, yang mencacah
**dokumen**, bukan pengadaan yang benar-benar terjadi. Dan kebocoran yang sama
ternyata ada di dua tempat lain yang menghitung dengan cara serupa:

- kartu **"Jumlah Realisasi"** dan **"Nilai Dipesan"** pada halaman `/pengadaan`;
- rekap pada `pdf.laporan-pengadaan`.

Yang menarik, halaman **detail** penyedia justru sudah jujur sejak awal: tiap
baris riwayat membawa badge statusnya, sehingga realisasi batal terlihat apa
adanya. Yang menyesatkan hanya angka ringkasnya.

Usul pertama atas laporan itu adalah menghapus baris realisasi ketika
dibatalkan, supaya angkanya otomatis benar. Usul itu ditolak; alasannya di bawah.

## Keputusan

### Satu aturan, tiga tempat

**Angka ringkas mencacah pengadaan yang benar-benar berjalan; yang dibatalkan
ditampilkan terpisah, tidak pernah dilebur ke dalamnya.**

Sebuah realisasi yang dibatalkan adalah dokumen yang pernah ada tetapi **tidak
pernah menjadi transaksi**: barangnya tidak datang, stok tidak bertambah, tidak
ada satu pun mutasi. Menghitungnya sebagai "pengadaan ke sekian" pada seorang
penyedia menyatakan sesuatu yang tidak pernah terjadi.

| Tempat | Angka utama | Angka pendamping |
|---|---|---|
| Daftar penyedia | pengadaan berjalan | "N dibatalkan" di bawahnya |
| Detail penyedia | pengadaan berjalan | disebut di kalimat pengantar |
| `/pengadaan` | `total`, `nilai` | `dibatalkan` di bawah kartu pertama |
| Laporan PDF | idem | idem, plus catatan kaki |

Pemisahan ini menyisakan invarian yang enak dibaca pada rekap realisasi:
`total = menunggu + diterima`.

### Ditampilkan, tidak dihitung

Baris realisasi yang dibatalkan **tetap muncul** di tabel riwayat penyedia, di
daftar realisasi, dan di baris laporan PDF maupun CSV — lengkap dengan nilai dan
badge statusnya. Penyaring status tetap dapat memisahkannya untuk ditinjau
sendiri.

Yang diubah hanya angka ringkasnya, dan angka pembatalan selalu ikut
ditampilkan. Tidak ada satu pun data yang hilang dari layar.

### Nilai dipesan ikut mengecualikan pembatalan

`nilai` dihitung dari rincian realisasi yang tidak dibatalkan. Akibatnya selisih
antara "Nilai Dipesan" dan "Nilai Diterima" kembali hanya punya **dua** sebab —
kiriman yang kurang dan penerimaan yang belum dikonfirmasi — sehingga catatan
kaki laporan yang sempat menyebut tiga sebab ditulis ulang.

### Penghapusan penyedia tetap terhalang

`Supplier::blockingRelations()` **tidak diubah**. `procurements.supplier_id`
memakai `restrictOnDelete`, jadi realisasi batal pun tetap menunjuk penyedianya
dan menghalangi penghapusan di tingkat basis data. Yang diperbaiki hanya
pesannya — menyebut secara eksplisit bahwa realisasi yang dibatalkan pun ikut
menghalangi, supaya keadaan "0 pengadaan tetapi tidak dapat dihapus" tidak
terbaca sebagai bug. Penyelesaiannya tetap sama:
**arsipkan** ([ADR 0003](0003-arsip-alih-alih-hapus.md)).

## Alternatif yang ditolak

**Menghapus baris realisasi ketika dibatalkan.** Usul pertama atas laporan ini,
dan memang membuat seluruh angka otomatis benar tanpa menyentuh satu pun
tampilan. Ditolak karena tiga akibatnya. *Pertama*, kolom `cancelled_by`,
`cancel_reason`, dan `cancel_note` yang baru ditambahkan
[ADR 0024](0024-pembatalan-realisasi-pengadaan.md) menjadi sia-sia — tidak ada
gunanya mencatat siapa membatalkan dan mengapa, pada baris yang lalu dibuang.
*Kedua*, menghapus barisnya membebaskan kembali indeks unik
`procurement_request_id`, sehingga usulan asalnya hidup lagi — membalik keputusan
"pembatalan bersifat terminal" secara diam-diam, lewat pintu belakang.
*Ketiga*, dan yang paling menentukan: pola "lima realisasi dibatalkan kepada
penyedia yang sama" justru **tepat hal yang ingin dilihat** pemeriksa pada
lembaga pemeriksa keuangan. Menghapusnya bukan membersihkan data, melainkan
menghapus temuan.

Yang salah memang angkanya, bukan datanya.

**Menyembunyikan baris yang dibatalkan dari daftar dan laporan.** Angkanya benar
dan layarnya bersih tanpa perlu kolom pendamping apa pun. Ditolak karena
membersihkan angka dengan cara menyembunyikan data adalah bentuk lain dari
berbohong: pengguna kehilangan kemampuan menjawab "apa yang terjadi dengan
pengadaan yang saya buat minggu lalu". Ditampilkan tetapi tidak dihitung
memberikan keduanya sekaligus.

**Membiarkan hitungannya dan hanya memperbaiki label kolomnya**, misalnya
"3× (termasuk yang dibatalkan)". Perubahan paling kecil. Ditolak karena
menyerahkan pekerjaan berhitung kepada pembaca: untuk tahu berapa pengadaan yang
sebenarnya, ia harus membuka halaman detail dan menghitung sendiri baris yang
batal. Angka yang butuh diterjemahkan bukan angka yang berguna.

**Menyediakan penyaring "sembunyikan yang dibatalkan" dan membiarkan
penggunanya memilih.** Fleksibel. Ditolak karena ini bukan soal selera: cacah
pengadaan seorang penyedia punya satu jawaban yang benar, dan menjadikannya
pilihan hanya memindahkan tanggung jawab menentukan kebenaran kepada pengguna.

## Konsekuensi

- Definisi cacahnya dipusatkan pada `SupplierController::cacahPengadaan()` dan
  dipakai bersama daftar maupun detail, sehingga kedua halaman tidak dapat
  berbeda pendapat.
- Satu kalimat catatan kaki pada
  [ADR 0023](0023-laporan-pengadaan-per-baris-barang.md) — yang menyebut
  pembatalan sebagai salah satu sebab selisih nilai — tidak lagi berlaku.
  ADR 0023 sengaja **tidak** ditandai "Digantikan": seluruh keputusan intinya
  (laporan berbutir barang, dua sisi nilai, BAST memakai `amount_received`)
  tetap berdiri, dan yang gugur hanya satu kalimat penjelas.
- Bersama [ADR 0025](0025-realisasi-dapat-diubah-sebelum-penerimaan.md),
  keluhan yang memicu kedua ADR ini tertutup dari dua arah: pembatalan berhenti
  lahir karena salah ketik, dan yang tetap lahir karena kegagalan nyata tidak
  lagi mengotori angka.
