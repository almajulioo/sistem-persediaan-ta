# Modul Dasbor

Halaman pertama setelah masuk, untuk **seluruh peran**. Isinya menyesuaikan
gate pengguna dan seluruhnya dihitung langsung dari data. Lihat
[ADR 0040](../keputusan/0040-dasbor-berbasis-peran-dari-data-langsung.md).

## Rute

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/dasbor` | `dashboard` | Dasbor (grup `auth`, tanpa gate) |

## Bagian

### Salam

"Selamat pagi/siang/sore/malam, {nama lengkap}" menurut jam Asia/Jakarta
(pagi 04–11, siang 11–15, sore 15–18), lencana peran, dan tanggal hari ini.

### Akses cepat

Ubin modul dengan ikon berlatar warna berbeda. Disaring dengan gate yang sama
dengan middleware rutenya.

| Ubin | Rute | Syarat |
|---|---|---|
| Permintaan Barang | `request.index` | semua |
| Master Barang | `items.index` | `akses-gudang` |
| Mutasi Stok | `mutations.index` | `akses-gudang` |
| Stok Opname | `opname.index` | `akses-gudang` |
| Usulan Pengadaan | `procurement-requests.index` | `akses-pengadaan` |
| Realisasi Pengadaan | `procurements.index` | `akses-pengadaan` |
| Usulan Barang Baru | `item-proposals.index` | `akses-pengadaan` |
| Penyedia Barang | `suppliers.index` | `akses-penyedia` |
| Manajemen Pengguna | `users.index` | `kelola-pengguna` |
| Log Aktivitas | `activity-logs.index` | `kelola-pengguna` |

### Perlu Ditindaklanjuti

Hanya baris yang jumlahnya > 0. Kosong → "Tidak ada yang perlu
ditindaklanjuti."

| Baris | Dihitung dari | Tautan | Syarat |
|---|---|---|---|
| Realisasi menunggu persetujuan | `procurements` `menunggu_persetujuan` | `procurements.index?status=…` | `setujui-pengadaan` |
| Usulan menunggu keputusan | `procurement_requests` `diajukan` | `procurement-requests.index?status=…` | `kelola-pengadaan` |
| Usulan siap direalisasi | `disetujui_menunggu_realisasi` | sda. | `kelola-pengadaan` |
| Realisasi ditolak | `ditolak` | `procurements.index?status=…` | `kelola-pengadaan` |
| Belum dibelanjakan | `proses_belanja` | sda. | `kelola-pengadaan` |
| Barang menunggu penerimaan | `menunggu_penerimaan` | sda. | `kelola-gudang` |
| Usulan barang baru | `item_proposals` `diajukan` | `item-proposals.index?status=diajukan` | `kelola-gudang` |
| Draf usulan pengadaan | **jumlah barang** di draf | `procurement-requests.draft.edit` | `kelola-gudang` |
| Opname belum selesai | `stock_opnames` `draf` | `opname.index?status=draf` | `kelola-gudang` |
| Permintaan menunggu persetujuan | `requests` `submitted` | `request.index?status=submitted` | Admin; Kepala Unit (unitnya saja) |
| Permintaan perlu diproses | `approved_by_head` | `request.index?status=…` | `kelola-permintaan` |
| Permintaan menunggu penyelesaian | `processed_by_staff` | `request.index?status=…` | Admin |
| Permintaan saya yang berjalan | milik sendiri, `submitted`/`approved_by_head`/`processed_by_staff`/`in_issuance` | `request.index` | Pengguna |

Seluruh baris permintaan mengecualikan `type = 'umum'`, sama dengan daftar
yang ditautkan.

### Kondisi Stok

- Bilah tersegmen Habis / Menipis / Aman / Berlebih atas barang **aktif**,
  memakai scope `Item::stockStatus()`; legenda berangka menautkan
  `items.index?stock=…&status=aktif`.
- "Perlu diisi ulang": lima barang aktif di bawah minimum, diurutkan menurut
  rasio stok terhadap minimum (habis lebih dulu), dengan bilah stok/minimum.
- Tautan hanya bila `akses-gudang`. Pejabat Pengadaan melihat bagian ini
  **tanpa tautan** — halaman barang tertutup baginya. Kasubbag Umum dan TI
  bertaut sejak [ADR 0041](../keputusan/0041-kasubbag-pengawas-baca-saja.md).

### Alur Pengadaan

Enam tahap dengan jumlah dan tautan ke daftar terfilter: Usulan Diajukan →
Menunggu Realisasi → Menunggu Persetujuan (+ "N ditolak") → Proses Belanja →
Menunggu Penerimaan → Diterima (total). Draf tidak dihitung. Lingkaran tahap
berwarna bila tahapnya berisi dokumen.

### Grafik

| Grafik | Isi | Syarat |
|---|---|---|
| Mutasi Stok | Jumlah transaksi masuk vs keluar per bulan, 6 bulan, menurut `mutation_date`, tanpa saldo awal | `akses-gudang` |
| Nilai Pengadaan Diterima | Σ `amount_received × unit_price` realisasi Diterima per bulan, menurut tanggal terima | `kelola-pengadaan` atau `setujui-pengadaan` |

Digambar ApexCharts lewat `resources/js/components/chart/dasbor.js`, data dari
atribut `data-grafik`. Digambar ulang saat tema berganti. Setiap grafik
disertai tabel `sr-only` untuk pembaca layar; grafik tanpa data diganti
kalimat pendek.

## Berkas

| Berkas | Isi |
|---|---|
| `app/Http/Controllers/DashboardController.php` | Seluruh perhitungan, satu metode privat per bagian |
| `resources/views/pages/dashboard/index.blade.php` | Tampilan |
| `resources/views/components/ui/icon.blade.php` | Komponen ikon SVG, dapat dipakai modul lain |
| `resources/js/components/chart/dasbor.js` | Grafik |
| `tests/Feature/DashboardTest.php` | 27 uji |
