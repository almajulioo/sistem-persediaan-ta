# ADR 0029 — Realisasi boleh melebihi usulan, dan boleh menambah barang

## Status

Diterima — 13 September 2026

## Konteks

Sebelum keputusan ini, realisasi pengadaan terikat ketat pada usulan
sumbernya di dua sisi: jumlah pesan per barang (`procurement_details.amount`)
tidak boleh melebihi jumlah yang disetujui usulan
(`procurement_request_details.amount_granted`), dan susunan barisnya persis
mengikuti rincian usulan yang disetujui — tidak bisa ditambah maupun
dikurangi, baik saat dibuat (`StoreProcurementRequest`) maupun saat diubah
(`UpdateProcurementRequest`).

Diminta agar Pejabat Pengadaan punya keleluasaan lebih saat membuat dan
mengatur realisasi: boleh memesan lebih banyak dari yang disetujui usulan,
dan boleh menambahkan barang yang sama sekali tidak ada di usulan sumbernya
— alasannya, keputusan pembelian akhir (termasuk kebutuhan tambahan yang baru
terlihat saat proses pengadaan berjalan) ada di tangan Pejabat Pengadaan,
bukan di tangan Pengelola Gudang yang mengajukan usulan. Perubahan ini juga
langkah awal menuju rencana lebih besar: realisasi yang bisa dibuat langsung
oleh Pejabat Pengadaan tanpa usulan sama sekali (dengan alasan tertulis),
yang akan menambahkan jenjang persetujuan baru ke Kasubbag Umum dan TI — tapi
itu di luar cakupan keputusan ini.

Dua opsi diajukan: (1) juga melonggarkan persetujuan usulan itu sendiri,
supaya Pejabat Pengadaan bisa menyetujui `amount_granted` lebih besar dari
`amount_request` Pengelola Gudang, atau (2) hanya melonggarkan sisi
realisasi. Opsi (1) ditolak — lihat "Alternatif yang ditolak".

## Keputusan

### Batas atas `amount` dicabut, di kedua tempat

`StoreProcurementRequest` dan `UpdateProcurementRequest` tidak lagi
membandingkan `amount` terhadap `amount_granted`. Validasi jumlah kini murni
`required|integer|min:1|max:999999`, sama untuk barang usulan maupun barang
tambahan. Persetujuan usulan sendiri **tidak berubah** —
`ApproveProcurementRequestRequest` tetap menolak `amount_granted` yang
melebihi `amount_request`; lihat "Alternatif yang ditolak" untuk alasannya.

### Baris tambahan: dua bentuk baris dalam satu payload

`items.*` sekarang punya dua bentuk yang saling eksklusif per baris:

- **Baris usulan** — mengirim `procurement_request_detail_id` (saat dibuat)
  atau `procurement_detail_id` (saat diubah); `item_id`-nya diturunkan
  server-side, persis pola lama.
- **Baris tambahan** — mengirim `item_id` langsung, tanpa id baris usulan
  apa pun. Server memvalidasi `item_id` itu ada di tabel `items`, dan bahwa
  tidak ada dua baris (usulan maupun tambahan) yang memakai `item_id` yang
  sama.

`procurement_details` tetap tidak menyimpan tautan balik ke baris usulannya
(alasan yang sama dengan sebelum ADR ini: `item_id` dijaga unik per
pengadaan). Untuk membedakan "baris usulan" dari "baris tambahan" saat
membaca kembali — dipakai `UpdateProcurementRequest::rincianDisetujui()` dan
Blade `edit.blade.php` — keanggotaan dicek lewat `item_id`, terhadap rincian
usulan yang `amount_granted > 0`. Ini konsisten dengan cara lama
`rincianDisetujui()` sudah bekerja, hanya sekarang keanggotaan itu juga
menentukan apakah barisnya boleh dihapus.

### Barang dari usulan wajib ada, barang tambahan bebas keluar-masuk

Baik saat dibuat maupun saat diubah, seluruh rincian usulan yang disetujui
(`amount_granted > 0`) **wajib** muncul di kiriman — persis prinsip lama
"realisasi wajib mencakup seluruh rincian yang disetujui"
([ADR 0021](0021-realisasi-dan-penerimaan-pengadaan.md)), sekarang ditegakkan
juga saat pembuatan (sebelumnya cuma disiplin UI, bukan validasi keras).
Baris tambahan sebaliknya boleh hilang dari kiriman kapan saja — pada
`update()`, baris yang hilang dan bukan berasal dari usulan berarti sengaja
dihapus, dan `ProcurementController::update()` menghapusnya sungguhan dari
`procurement_details`. Aman dilakukan karena hapus-baris hanya mungkin
selama `MenungguPenerimaan` — belum ada stok atau mutasi yang bergantung
padanya (alasan yang sama yang membuat pembatalan realisasi aman, ADR 0024).

### Tampilan: dua bagian terpisah, bukan satu tabel bercampur

`create.blade.php` dan `edit.blade.php` memisahkan "Barang dari Usulan"
(tabel statis Blade, tanpa tombol hapus, format lama tapi tanpa atribut
`max`) dari "Barang di Luar Usulan" (daftar dinamis Alpine.js, dengan pemilih
barang bergaya pencarian — pola yang sama dengan pemilih barang pada
`procurement-requests/draft.blade.php` — dan tombol Hapus per baris). Dua
bagian terpisah dipilih ketimbang satu tabel dengan tombol hapus yang
kadang aktif kadang tidak, supaya batasan "barang usulan tidak bisa dihapus"
terlihat dari struktur halaman, bukan cuma dari tombol yang di-disable.

## Alternatif yang ditolak

**Juga melonggarkan persetujuan usulan** (`amount_granted` boleh melebihi
`amount_request`). Ditolak karena kebebasan yang sama akan ada di dua
tempat sekaligus begitu realisasi sudah longgar — usulan yang disetujui
lebih besar dari yang diminta tidak menambah kemampuan apa pun yang belum
bisa dicapai lewat barang tambahan pada realisasi, tapi melemahkan makna
`amount_granted` sebagai representasi kebutuhan Pengelola Gudang yang
sesungguhnya. Konsekuensinya: angka "disetujui" pada usulan akan selalu
tampak lebih kecil atau sama dengan yang akhirnya direalisasikan bila
Pejabat Pengadaan memesan lebih — dianggap wajar, karena usulan mencatat
kebutuhan asli, sedangkan realisasi mencatat keputusan pembelian aktual.

**Menyimpan tautan eksplisit `procurement_detail.procurement_request_detail_id`**
alih-alih mengklasifikasikan lewat `item_id`. Lebih eksplisit dan tidak
bergantung pada invarian "satu barang cuma sekali per usulan", tapi ditolak
untuk menghindari migrasi skema yang tidak diperlukan — invarian itu sudah
dijaga sejak sebelum ADR ini (lihat komentar lama di
`UpdateProcurementRequest::rincianDisetujui()`), dan tetap dijaga sekarang
lewat validasi `item_id` yang tidak boleh dobel dalam satu realisasi.

## Konsekuensi

- `app/Http/Requests/Pengadaan/StoreProcurementRequest.php` dan
  `UpdateProcurementRequest.php`: batas `amount ≤ amount_granted` dicabut,
  baris tambahan (`item_id` tanpa id baris usulan) diterima, kelengkapan
  rincian usulan ditegakkan sebagai validasi keras di kedua form request.
- `ProcurementController::store()` dan `update()` menangani dua bentuk baris,
  dan `update()` menghapus baris tambahan yang tidak lagi dikirim.
- `resources/views/pages/procurements/create.blade.php` dan
  `edit.blade.php` ditulis ulang dengan Alpine.js untuk bagian "Barang di
  Luar Usulan"; atribut `max` pada input jumlah barang usulan dihapus.
- Tidak ada migrasi skema — `procurement_details` tidak bertambah kolom.
- Test baru di `ProcurementTest.php` dan `ProcurementEditTest.php` untuk:
  jumlah melebihi disetujui (diterima), barang tambahan (diterima),
  kelengkapan rincian usulan tetap wajib (ditolak bila kurang), barang
  tambahan boleh dihapus kembali, barang usulan tidak boleh dihapus.
- Langkah berikutnya yang **belum** dikerjakan (di luar cakupan ADR ini):
  realisasi yang dibuat langsung oleh Pejabat Pengadaan tanpa usulan, dan
  jenjang persetujuan tambahan ke Kasubbag Umum dan TI.
