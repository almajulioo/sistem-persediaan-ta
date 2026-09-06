# Modul Pengadaan

> **Status: selesai.** Dibangun empat tahap, keempatnya selesai
> 6 September 2026.

Mengelola pengadaan barang persediaan dari usulan sampai barang masuk gudang.

## Batas cakupan

Sistem hanya menangkap proses pengadaan **di dalam kantor**. Pembelian
barangnya sendiri — mencari penyedia, menawar, membayar — terjadi **di luar
sistem**, di antara realisasi dan penerimaan.

```
Pengelola Gudang       Pejabat Pengadaan        [LUAR SISTEM]      Pengelola Gudang
      │                        │                      │                    │
  Usulan  ──disetujui──▶  Realisasi  ──────▶  pembelian nyata  ──▶  Penerimaan
                                                                          │
                                                                    stok bertambah
                                                                          │
                                                                    BAST dicetak
```

Rincian keputusan: [ADR 0016](../keputusan/0016-alur-pengadaan-tiga-dokumen.md).

## Pemisahan tugas

Tidak ada satu peran pun yang dapat menjalankan seluruh siklus sendirian:

| Tahap | Pelaku | Tercatat di |
|---|---|---|
| Mengusulkan | Pengelola Gudang | `procurement_requests.user_id` |
| Menyetujui / menolak | Pejabat Pengadaan | `procurement_requests.status` |
| Merealisasi | Pejabat Pengadaan | `procurements.user_id` |
| Mengonfirmasi diterima | Pengelola Gudang | `procurement_receipts.received_by` |

Admin lolos seluruhnya lewat `Gate::before`.

## Aturan bisnis yang ditegakkan

1. Realisasi hanya dari usulan ber-status `Disetujui`.
2. Barang pada realisasi wajib ada di rincian usulan dengan `amount_granted > 0`.
3. `procurement_details.amount` ≤ `amount_granted`.
4. Satu usulan → paling banyak satu realisasi (`procurement_request_id` unik).
5. **Stok bertambah sebanyak `amount_received`, bukan `amount`**
   ([ADR 0017](../keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md)).
6. `MutationType::Masuk` ber-`isManual() === false`, sehingga penerimaan
   pengadaan adalah satu-satunya sumber sah stok masuk.

## Tabel

| Tabel | Isi |
|---|---|
| `suppliers` | Data induk penyedia; identitasnya dicetak di BAST |
| `procurement_requests` | Usulan pengadaan; `source` membedakan tiga asal-usul |
| `procurement_request_details` | Rincian barang usulan; `amount_granted` merangkap keputusan per barang |
| `procurements` | Realisasi pengadaan; menautkan usulan ke penyedia |
| `procurement_details` | Rincian realisasi; `amount` dipesan, `amount_received` datang, `unit_price` harga satuan |
| `procurement_receipts` | Peristiwa penerimaan: siapa, kapan, nomor BAST |
| `procurement_documents` | Berkas BAST pindai dan SPK/kontrak, di disk privat |

---

## Tahap 1 — Skema dan Penyedia Barang ✅

### Penyedia Barang

Data induk penyedia. Alasan keberadaannya bukan normalisasi, melainkan BAST:
dokumen serah terima memuat identitas penyedia, dan mengetik ulang alamat
beserta narahubung pada tiap dokumen persis friksi yang membuat orang enggan
memakai sistem.

**Rute** — seluruhnya di bawah gate `kelola-pengadaan`:

| Metode | URL | Nama | Keterangan |
|---|---|---|---|
| GET | `/penyedia` | `suppliers.index` | Daftar, pencarian, penyaring status |
| GET | `/penyedia/create` | `suppliers.create` | Formulir tambah |
| POST | `/penyedia` | `suppliers.store` | |
| GET | `/penyedia/{supplier}` | `suppliers.show` | Identitas + riwayat pengadaan |
| GET | `/penyedia/{supplier}/edit` | `suppliers.edit` | |
| PUT | `/penyedia/{supplier}` | `suppliers.update` | |
| PATCH | `/penyedia/{supplier}/status` | `suppliers.toggle-status` | Arsip / aktifkan |
| DELETE | `/penyedia/{supplier}` | `suppliers.destroy` | Ditolak bila sudah dipakai |

**Aturan.**

- `supplier_name` wajib dan unik; `address` wajib **karena dicantumkan di BAST**.
- Telepon, surel, narahubung, dan NPWP boleh kosong — diisi sesuai apa yang
  diketahui saat penyedia didaftarkan.
- Validasi NPWP dan telepon sengaja longgar (hanya menyaring karakter yang
  jelas keliru): format resmi belum dikonfirmasi, dan validasi yang terlalu
  ketat hanya akan menolak data yang sebenarnya benar.
- Penyedia yang sudah dipakai realisasi **diarsipkan, bukan dihapus**
  ([ADR 0003](../keputusan/0003-arsip-alih-alih-hapus.md)) — BAST yang sudah
  terbit atas namanya harus tetap dapat dibaca.

### Perubahan skema pendukung tahap berikutnya

Sudah mendarat di Tahap 1 walau fiturnya menyusul:

- `procurement_details.amount_received` (nullable) dan `unit_price`.
- `procurement_documents` beserta enum `ProcurementDocumentType`.
- `procurement_receipts.file_url` dihapus.
- `procurement_request_details.status` dihapus.
- Gate `akses-pengadaan`.

---

## Tahap 2 — Usulan Pengadaan ✅

Pengajuan, persetujuan/penolakan, jalur pintas dari halaman barang, dan empat
jenis notifikasi baru. Rincian keputusan:
[ADR 0020](../keputusan/0020-notifikasi-usulan-dan-persetujuan-sebagian.md).

**Rute** — mengusulkan dan membaca dipisah gate berbeda:

| Metode | URL | Nama | Gate | Keterangan |
|---|---|---|---|---|
| GET | `/usulan-pengadaan` | `procurement-requests.index` | `akses-pengadaan` | Daftar, penyaring status dan sumber |
| GET | `/usulan-pengadaan/create` | `procurement-requests.create` | `kelola-gudang` | Formulir usulan; menerima `?item_id=` dari halaman barang |
| POST | `/usulan-pengadaan` | `procurement-requests.store` | `kelola-gudang` | |
| GET | `/usulan-pengadaan/{procurementRequest}` | `procurement-requests.show` | `akses-pengadaan` | Detail + formulir keputusan bila masih Diajukan |
| PATCH | `/usulan-pengadaan/{procurementRequest}/setujui` | `procurement-requests.approve` | `kelola-pengadaan` | Menerima peta `granted[]` per barang, sekali putus |
| PATCH | `/usulan-pengadaan/{procurementRequest}/tolak` | `procurement-requests.reject` | `kelola-pengadaan` | Mengisi `amount_granted = 0` pada seluruh barang |

**Aturan.**

- Persetujuan **satu dokumen sekali putus**, bukan satu aksi per barang:
  `granted[procurement_request_detail_id] => jumlah` diterapkan ke seluruh
  rincian dalam satu transaksi. Barang dengan `granted = 0` berarti tidak
  disetujui tanpa menolak seluruh usulan — inilah wujud "disetujui sebagian".
- Usulan yang sudah diputuskan (`Disetujui`/`Ditolak`) tidak dapat diputuskan
  ulang; rute `approve`/`reject` menolaknya dengan pesan, bukan 500.
- `source` bukan field pilihan pengguna: formulir yang dibuka lewat tombol
  "Ajukan Usulan Pengadaan" pada halaman barang (stok Habis/Menipis) otomatis
  bersumber `PemantauanStok` dengan barang sudah terpilih; selain itu `Manual`.
  Sumber `DariPermintaan` sengaja belum bisa dipilih lewat formulir ini —
  integrasi ke sub sistem Permintaan belum ada titik masuknya.

**Notifikasi baru** (lihat [modul/notifikasi.md](notifikasi.md)):
`UsulanMenungguPersetujuan` dan `UsulanMenggantung` (keadaan, saling
eksklusif dengan ambang 7 hari), `UsulanDisetujui` dan `UsulanDitolak`
(peristiwa ke pengaju).

## Tahap 3 — Realisasi dan Penerimaan ✅

Realisasi pengadaan dari usulan yang sudah disetujui, dan konfirmasi
penerimaan barang yang menambah stok lewat `StockLedger`. Rincian keputusan:
[ADR 0021](../keputusan/0021-realisasi-dan-penerimaan-pengadaan.md).

**Rute realisasi** — `ProcurementController`, tiga gate seperti Tahap 2:

| Metode | URL | Nama | Gate | Keterangan |
|---|---|---|---|---|
| GET | `/pengadaan` | `procurements.index` | `akses-pengadaan` | Daftar, penyaring status |
| GET | `/pengadaan/create` | `procurements.create` | `kelola-pengadaan` | Menerima `?procurement_request_id=` dari halaman usulan |
| POST | `/pengadaan` | `procurements.store` | `kelola-pengadaan` | |
| GET | `/pengadaan/{procurement}` | `procurements.show` | `akses-pengadaan` | Detail + tombol Konfirmasi Penerimaan bila masih Menunggu Penerimaan |

**Rute penerimaan** — `ProcurementReceiptController`, gate `kelola-gudang`,
peristiwa sekali jadi tanpa rute ubah/hapus:

| Metode | URL | Nama | Keterangan |
|---|---|---|---|
| GET | `/pengadaan/{procurement}/penerimaan/create` | `procurement-receipts.create` | Formulir konfirmasi, saran nomor BAST berurut |
| POST | `/pengadaan/{procurement}/penerimaan` | `procurement-receipts.store` | Menjalankan `ProcurementReceiptPoster` |

**Aturan.**

- Formulir realisasi hanya dapat dibuka dari usulan `Disetujui` yang belum
  punya realisasi — tombol **Realisasikan** pada halaman usulan, bukan
  halaman berdiri sendiri. Wajib mencakup **seluruh** rincian usulan yang
  disetujui (`amount_granted > 0`), karena `procurement_request_id` unik
  berarti satu usulan paling banyak menghasilkan satu realisasi selamanya —
  barang yang dikecualikan sekarang tidak akan pernah punya kesempatan kedua.
- `item_id` pada rincian realisasi diturunkan server-side dari
  `procurement_request_detail_id`, bukan dikirim klien — pola yang sama
  dengan persetujuan usulan Tahap 2.
- `ProcurementReceiptPoster::post()` mengikuti pola `OpnamePoster`: transaksi
  terkunci, `StockLedger::record(type: Masuk, amount: amount_received)` per
  barang (baris bernilai nol tidak menghasilkan mutasi), referensi menunjuk
  `Procurement` (alias morph `pengadaan`), status realisasi berubah menjadi
  `Diterima`, lalu `AuditLogger` dan notifikasi dipanggil di luar transaksi.
- Kiriman yang **lebih** dari pesanan tidak dibatasi validasi — hanya
  kekurangan kiriman yang jadi perhatian ADR 0017.
- `received_at` adalah tanggal bisnis yang dapat diedit (seperti
  `procurement_date`/`opname_date`/`mutation_date`), bukan dikunci ke `now()`.

**Notifikasi baru**: `RealisasiMenungguPenerimaan` (keadaan, ke Pengelola
Gudang + Admin) dan `PenerimaanDikonfirmasi` (peristiwa, ke Pejabat Pengadaan
yang merealisasi).

**Belum dikerjakan di tahap ini**: status `ProcurementStatus::Dibatalkan`
masih tanpa pemicu — belum ada kebutuhan lapangan yang mengonfirmasinya.

## Tahap 4 — Dokumen, BAST, dan laporan ✅

Keluaran cetak dan berkas pendukung. Rincian keputusan:
[ADR 0022](../keputusan/0022-wewenang-unggah-dan-hapus-berkas-pengadaan.md)
(wewenang berkas) dan
[ADR 0023](../keputusan/0023-laporan-pengadaan-per-baris-barang.md)
(bentuk laporan dan isi BAST).

**Rute** — seluruhnya di bawah gate `akses-pengadaan`, karena mencetak dan
melampirkan bukti bukan bagian dari pemisahan tugas ADR 0016:

| Metode | URL | Nama | Keterangan |
|---|---|---|---|
| GET | `/pengadaan/laporan/pdf` | `procurements.pdf` | Laporan pengadaan, mengikuti penyaring aktif |
| GET | `/pengadaan/laporan/csv` | `procurements.csv` | Ekspor CSV, kolom identik dengan PDF |
| GET | `/pengadaan/{procurement}/bast` | `procurements.bast` | BAST siap tanda tangan |
| POST | `/pengadaan/{procurement}/dokumen` | `procurement-documents.store` | Unggah BAST pindai atau SPK/kontrak |
| GET | `/pengadaan/{procurement}/dokumen/{document}/unduh` | `procurement-documents.download` | Unduhan terautentikasi; tidak ada URL berkas |
| DELETE | `/pengadaan/{procurement}/dokumen/{document}` | `procurement-documents.destroy` | Hanya pengunggah atau Admin |

### Berkas pendukung

`App\Support\DocumentStorage` memegang seluruh konvensi penyimpanan: path
`pengadaan/{procurement_id}/{uuid}.{ext}` di disk **privat**
(`storage/app/private`), pengacakan nama berkas, penghapusan, dan pemeriksaan
keberadaan berkas. Controller tidak pernah menyebut nama disk maupun menyusun
path sendiri.

**Aturan.**

- Unggahan: `mimes:pdf,jpg,jpeg,png`, maksimal 5 MB, jenis divalidasi dengan
  `Rule::enum(ProcurementDocumentType::class)`.
- `uploaded_by` diisi dari sesi, tidak pernah dari klien; tiap unggahan dan
  tiap penghapusan menulis satu baris `audit_logs`.
- Nama berkas di disk diacak; nama asli disimpan di `original_name` dan
  dikembalikan utuh saat diunduh.
- Berkas yang tidak menempel pada pengadaan di URL dijawab **404**, bukan 403 —
  403 justru membenarkan bahwa berkas itu ada.
- Berkas yang barisnya ada tetapi fisiknya hilang di disk (misalnya setelah
  pemulihan cadangan) menghasilkan pesan ramah, bukan galat 500.

### BAST

Dicetak dari sistem lalu ditandatangani, dipindai, dan diunggah kembali —
urutan yang ditetapkan [ADR 0018](../keputusan/0018-berkas-unggahan-di-disk-privat.md).

**Aturan.**

- Seluruh angka pada BAST adalah `amount_received`, bukan `amount`
  ([ADR 0017](../keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md)).
- Baru terbit setelah penerimaan dikonfirmasi; sebelum itu rute menolak dengan
  redirect berpesan.
- Kiriman kurang tetap menghasilkan BAST, dengan satu paragraf catatan yang
  menyebut jenis dan jumlah kekurangannya — keadaan itu justru yang paling
  membutuhkan dokumen bertanda tangan.
- Memuat identitas penyedia (nama, alamat, NPWP, narahubung) sebagai PIHAK
  PERTAMA dan penerimanya sebagai PIHAK KEDUA — inilah alasan `suppliers.address`
  wajib diisi sejak Tahap 1.

### Laporan pengadaan

**Satu baris = satu barang pada satu realisasi**, bukan satu dokumen, sehingga
laporan terbaca sebagai daftar barang yang diadakan. Kolom dokumen (tanggal,
nomor realisasi, penyedia, status, nomor BAST) diulang pada tiap baris.

**Aturan.**

- Empat penyaring dipakai bersama layar, PDF, CSV, dan penjaga batas ekspor:
  status, penyedia, tanggal awal, tanggal akhir — satu definisi
  (`Procurement::scopeFilter`), empat pemakai.
- Rentang tanggal bekerja pada `procurement_date`, dan laporan diurutkan
  **menaik** menurut tanggal itu. Sengaja berbeda dari buku mutasi, yang
  [ADR 0005](../keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)
  haruskan diurutkan menurut waktu pencatatan karena `stock_after` adalah saldo
  berjalan; pengadaan tidak punya saldo berjalan apa pun.
- Rekap membawa **dua** nilai berdampingan — nilai dipesan dan nilai diterima —
  dan selisihnya adalah kekurangan kiriman penyedia. Rekap di layar mengikuti
  penyaring, bukan seluruh basis data, supaya angka di kartu selalu sama dengan
  angka di laporan yang dicetak dari halaman yang sama.
- Batas ekspor 2.000 realisasi, lebih ketat daripada 5.000 baris pada laporan
  mutasi, karena satu realisasi membawa seluruh rincian barangnya.

### Notifikasi baru

`BastBelumDiunggah` (keadaan, ke Pengelola Gudang + Admin): muncul bila
penerimaan sudah dikonfirmasi lebih dari **3 hari** lalu tetapi berkas BAST
belum diunggah, dan menutup dirinya sendiri begitu berkasnya masuk. Inilah
imbangan dari kelonggaran ADR 0018 — penerimaan sengaja tidak menunggu berkas
supaya stok tidak tertahan, dan justru karena itu ketiadaan berkas perlu
ditagih.

## Butir yang masih menunggu lapangan

**Format nomor dokumen resmi BPK** untuk `procurement_receipts.bast_number`
belum dikonfirmasi. Formulir konfirmasi penerimaan sudah menyediakan saran
nomor berurut (`{urutan}/BAST/{tahun}`) yang boleh ditimpa bebas, tanpa
validasi format yang dipaksakan.
