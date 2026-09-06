# ADR 0020 — Notifikasi usulan pengadaan dan persetujuan sebagian per barang

## Status

Diterima — 6 September 2026

## Konteks

[ADR 0016](0016-alur-pengadaan-tiga-dokumen.md) menetapkan alur tiga dokumen
dan pemisahan tugas, tetapi belum menetapkan dua hal yang baru terlihat begitu
Tahap 2 (usulan pengadaan) benar-benar dikodekan:

1. **Bagaimana persetujuan sebagian ditangkap.** Aturan bisnis "barang pada
   realisasi wajib ada di rincian usulan dengan `amount_granted > 0`" sudah
   ada sejak ADR 0016, tetapi mekanismenya — satu keputusan per barang, atau
   satu keputusan per dokumen yang menyentuh banyak barang sekaligus — belum
   diputuskan.
2. **Katalog notifikasi usulan.** [modul/notifikasi.md](../modul/notifikasi.md)
   baru mencatat kandidat kasar ("usulan menunggu persetujuan"), belum jenis
   yang benar-benar dibangun.

## Keputusan

**Persetujuan satu dokumen, sekali putus, mencakup seluruh barang.**
`ProcurementRequestController::approve()` menerima peta `granted[procurement_request_detail_id]
=> jumlah` dan menerapkannya ke seluruh rincian dalam satu transaksi, lalu
sekali saja mengubah `procurement_requests.status` menjadi `Disetujui`. Bukan
satu aksi per barang. Barang dengan `granted = 0` berarti tidak disetujui
tanpa menolak seluruh dokumen — inilah yang mewujudkan "disetujui sebagian"
dari ADR 0016.

**Menolak mengisi `amount_granted = 0` secara eksplisit pada seluruh barang**,
bukan membiarkannya `null`. Nilai `null` sudah bermakna "belum diputuskan"
pada `ProcurementRequestDetail::sudahDiputuskan()`; usulan yang ditolak sudah
diputuskan, jadi barisnya tidak boleh terlihat seperti masih menunggu.

**Empat jenis notifikasi baru**, mengikuti pola keadaan/peristiwa yang sudah
ada ([ADR 0013](0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md)):

| Jenis | Sifat | Penerima | Menutup diri |
|---|---|---|---|
| `UsulanMenungguPersetujuan` | Keadaan | Pejabat Pengadaan + Admin | Begitu usulan diputuskan, atau menggantung |
| `UsulanMenggantung` | Keadaan | Pejabat Pengadaan + Admin + pengaju | Begitu usulan diputuskan |
| `UsulanDisetujui` | Peristiwa | Pengaju | — |
| `UsulanDitolak` | Peristiwa | Pengaju | — |

`UsulanMenungguPersetujuan` dan `UsulanMenggantung` dibuat **saling
eksklusif** dengan ambang 7 hari, meniru persis pasangan
`OpnameSiapDiposting`/`OpnameDrafMenggantung`: usulan yang sama tidak perlu
dua notifikasi berbeda tentang dokumen yang belum diputuskan, dan pengaju
ikut diberi tahu begitu usulannya sendiri menggantung karena ia paling
berkepentingan usulannya segera diputuskan.

`NotificationScanner::periksaSeluruhUsulan()` menyapu **seluruh** baris
`ProcurementRequest`, bukan hanya yang berstatus Diajukan — pola yang sama
dengan `periksaOpname()` menyapu seluruh `StockOpname` — supaya usulan yang
baru diputuskan tetapi pemeriksaan reaktifnya sempat gagal tetap ikut ditutup
oleh perintah terjadwal.

**`source` bukan field yang dipilih pengguna.** Formulir usulan tidak
menampilkan dropdown sumber; nilainya diturunkan dari titik masuk formulir —
datang lewat parameter `item_id` (tombol "Ajukan Usulan Pengadaan" pada
halaman barang yang stoknya Habis/Menipis) berarti `PemantauanStok`, selain
itu `Manual`. Sesuai rencana ADR 0016, notifikasi Stok Habis/Menipis sendiri
tetap menautkan ke halaman barang, bukan langsung ke formulir usulan — tombol
barulah yang ada di halaman barang.

**Sumber `DariPermintaan` sengaja diblokir di lapisan validasi tahap ini**
(`StoreProcurementRequestRequest` hanya menerima `manual`/`pemantauan_stok`).
Integrasi ke `requests` milik sub sistem Permintaan lewat
`reference_type`/`reference_id` sudah tersedia di skema (ADR 0015) tetapi
belum ada titik masuk aplikasi yang mengisinya, sehingga membiarkannya
terpilih hanya akan menyimpan usulan dengan referensi kosong yang menyesatkan.

## Alternatif yang ditolak

**Satu aksi persetujuan per barang** (`PATCH .../rincian/{detail}/setujui`).
Lebih granular, tetapi usulan dengan banyak barang akan butuh banyak
permintaan HTTP untuk satu keputusan yang secara bisnis memang diambil
sekaligus oleh satu orang pada satu waktu. Ditolak karena menambah kerumitan
tanpa manfaat: tidak ada kebutuhan lapangan untuk menyetujui barang satu per
satu pada waktu berbeda.

**Notifikasi tunggal "usulan berubah status"** mencakup pengajuan baru,
persetujuan, dan penolakan sekaligus. Ditolak karena mencampur keadaan
(menunggu) dengan peristiwa (disetujui/ditolak) dalam satu jenis enum,
bertentangan langsung dengan prinsip pemisahan ADR 0013 yang sudah terbukti
menjaga lonceng tetap layak dibaca di modul Opname dan Stok.

**Dropdown sumber pada formulir usulan.** Sempat dipertimbangkan supaya
Pengelola Gudang bisa menandai usulan "manual" versus "hasil pantauan" sendiri
secara sadar. Ditolak: pembedaan itu sudah otomatis benar dari titik masuk
formulir, dan meminta pengguna memilihnya sendiri hanya membuka celah salah
pilih tanpa manfaat tambahan.

## Konsekuensi

- Rute `usulan-pengadaan/create` (GET, gate `kelola-gudang`) harus terdaftar
  di `routes/web.php` **sebelum** rute `usulan-pengadaan/{procurementRequest}`
  (GET, gate `akses-pengadaan`), walau keduanya berada di grup middleware
  gate yang berbeda — kalau tidak, "create" akan tertangkap sebagai parameter
  usulan dan gagal ditemukan sebagai model. Dicatat sebagai gotcha operasional
  di `CLAUDE.md`.
- `ApproveProcurementRequestRequest::withValidator()` membaca rincian usulan
  langsung dari model yang sudah terikat rute (`$this->route('procurementRequest')`),
  pola yang sama dengan `UpdateOpnameCountRequest`, supaya batas `amount_request`
  tiap barang diperiksa terhadap data sesungguhnya, bukan nilai yang dikirim
  klien.
- Tahap 3 (Realisasi dan Penerimaan) akan menambah jenis notifikasi
  sendiri untuk status *Menunggu Penerimaan*, mengikuti pola yang sama —
  bukan bagian dari ADR ini.
