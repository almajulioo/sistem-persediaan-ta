# ADR 0016 — Alur pengadaan tiga dokumen dan pemisahan tugas

## Status

Diterima — 6 September 2026

## Konteks

[ADR 0015](0015-usulan-pengadaan-dan-penerimaan-barang.md) membereskan
skemanya, tetapi tidak pernah menuliskan **proses bisnisnya**: siapa melakukan
apa, dalam urutan apa, dan sampai mana sistem ikut campur.

Batas cakupan itu ditegaskan narasumber utama (Kasubbag Umum dan TI): sistem
hanya menangkap proses pengadaan **di dalam kantor**. Pembelian barangnya
sendiri — mencari penyedia, menawar, membayar — terjadi di luar sistem. Setelah
barang datang, prosesnya kembali masuk sistem.

Risiko yang mengintai rancangan seperti ini adalah menjadi **formalitas**:
sistem yang hanya mencatat ulang apa yang sudah terjadi, dari kertas yang
sudah ditandatangani, akan diisi terlambat, seadanya, atau tidak sama sekali.
Karena itu pembagian perannya harus punya alasan yang lebih kuat daripada
"supaya ada pembagian".

## Keputusan

**Tiga dokumen berurutan, masing-masing baris tersendiri, masing-masing punya
pemilik peran yang berbeda.**

| Dokumen | Tabel | Dibuat oleh | Status |
|---|---|---|---|
| Usulan Pengadaan | `procurement_requests` | Pengelola Gudang | `Diajukan` → `Disetujui` \| `Ditolak` |
| Realisasi Pengadaan | `procurements` | Pejabat Pengadaan | `MenungguPenerimaan` → `Diterima` \| `Dibatalkan` |
| Penerimaan (BAST) | `procurement_receipts` | Pengelola Gudang | — (peristiwa sekali jadi) |

Pembelian nyata terjadi di antara dokumen kedua dan ketiga, **di luar sistem**.

### Pemisahan tugas tercermin di skema, bukan hanya di alur

Rantainya sengaja dibuat sedemikian rupa sehingga tidak ada satu peran pun yang
dapat menjalankan seluruh siklus sendirian:

- yang **mengusulkan** (Pengelola Gudang) bukan yang **menyetujui** (Pejabat
  Pengadaan);
- yang **merealisasi** (Pejabat Pengadaan, tercatat di `procurements.user_id`)
  bukan yang **mengonfirmasi barang diterima** (Pengelola Gudang, tercatat di
  `procurement_receipts.received_by`).

Kolom `received_by` yang terpisah itulah yang membuat
[ADR 0008](0008-penerimaan-barang-oleh-pengelola-gudang.md) berlaku di tingkat
data, bukan sekadar kebiasaan aplikasi yang bisa dilanggar diam-diam.

### Aturan penelusuran yang ditegakkan validasi

Setiap penambahan stok harus dapat ditelusuri mundur sampai ke usulan yang
disetujui:

1. Realisasi hanya boleh dibuat dari usulan ber-status `Disetujui`.
2. Barang pada realisasi **wajib** ada pada rincian usulan dengan
   `amount_granted > 0` — tidak boleh menyelipkan barang yang tidak diusulkan.
3. `procurement_details.amount` ≤ `amount_granted` — tidak boleh membeli lebih
   banyak dari yang disetujui.
4. `procurements.procurement_request_id` bersifat **wajib dan unik**: satu
   usulan menghasilkan paling banyak satu realisasi, dan tidak ada realisasi
   tanpa usulan.

Aturan ini didukung sifat `MutationType::Masuk` yang sudah ada sejak
[ADR 0006](0006-mutationtype-merangkap-alasan-manual.md):
`isManual()`-nya bernilai `false`, sehingga jenis mutasi itu tidak dapat
diketik lewat formulir mutasi manual. **Penerimaan pengadaan adalah
satu-satunya sumber sah stok masuk.**

### Tiga asal-usul usulan

`ProcurementRequestSource` membedakan dari mana usulan lahir: `DariPermintaan`,
`PemantauanStok`, `Manual`. Jalur `PemantauanStok` sengaja dijadikan alur
kelas satu — notifikasi Stok Habis/Menipis mengantar ke halaman barang, yang
menyediakan tombol langsung ke formulir usulan dengan barang itu sudah terisi.
Inilah yang menutup lingkaran antara memantau masalah dan menindaklanjutinya.

## Alternatif yang ditolak

**Dua dokumen saja: usulan dan realisasi, dengan penerimaan sebagai perubahan
status pada realisasi.** Lebih sedikit tabel, dan penerimaan memang selalu
menutup satu realisasi. Ditolak karena penerimaan punya pelaku, waktu, dan
nomor dokumen sendiri yang berbeda dari realisasinya. Menempelkannya sebagai
kolom pada `procurements` akan mencampur dua peran ke dalam satu baris dan
menghapus jejak pemisahan tugas yang justru menjadi inti ADR 0008.

**Satu dokumen saja: pengadaan yang statusnya berjalan dari diajukan sampai
diterima.** Paling ringkas. Ditolak dengan alasan yang sama seperti pada ADR
0015: usulan yang ditolak tidak pernah menjadi pengadaan, sehingga barisnya
akan menyimpan status yang tidak pernah berlaku baginya.

**Mengizinkan realisasi tanpa usulan** untuk pembelian darurat. Ditolak untuk
versi ini — lihat ADR 0015. Belum ada konfirmasi lapangan bahwa kebutuhan itu
nyata, dan melonggarkannya berarti membuka jalur stok masuk yang tidak dapat
ditelusuri.

**Menambah peran `PenerimaBarang` tersendiri.** Sempat dipertimbangkan supaya
pemisahan tugas lebih tegas lagi. Ditolak karena narasumber menegaskan peran
itu tidak ada di lapangan: yang menerima barang memang Pengelola Gudang.
Menambah peran yang tidak dipakai hanya menambah layar yang kosong.

## Konsekuensi

- Modul Pengadaan berdiri di atas tiga controller terpisah, bukan satu
  controller gemuk dengan banyak aksi status.
- Pengelola Gudang perlu dapat **membaca** dokumen pengadaan meski tidak boleh
  mengubahnya, dan sebaliknya. Karena middleware `can:` yang ditumpuk bersifat
  DAN dan bukan ATAU, ditambahkan gate `akses-pengadaan` yang meloloskan kedua
  peran sekaligus.
- Alur `DariPermintaan` menyediakan kolom `reference_type`/`reference_id` yang
  menunggu sub sistem Permintaan. Itu **titik integrasi, bukan dependensi** —
  dua sumber lainnya berfungsi penuh tanpanya.
- Karena pembelian terjadi di luar sistem, jeda antara realisasi dan penerimaan
  bisa panjang dan tidak terpantau. Itu ditangani notifikasi keadaan, bukan
  dengan memaksa orang memutakhirkan status secara manual.
