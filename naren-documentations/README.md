# Dokumentasi Naren — Pengadaan & Manajemen Gudang

Dokumentasi pengembangan **sub sistem pengadaan dan manajemen gudang** pada
sistem persediaan BPK Perwakilan Provinsi Lampung.

Repositori ini dikerjakan berdua dan menghasilkan **dua skripsi terpisah dengan
judul berbeda**, satu untuk tiap sub sistem. Karena itu dokumentasinya pun
dipisah: berkas di folder ini milik Narendra, sedangkan Alma memelihara
dokumentasinya sendiri untuk sub sistem permintaan barang.

Seluruh berkas ditulis dalam Markdown supaya ikut versi di Git, terbaca langsung
di editor, dan potongannya dapat dipakai ulang untuk naskah tugas akhir.

> `README.md` di root repositori **bukan** tempat dokumentasi pengembangan.
> Berkas itu hanya memuat hal yang diperlukan untuk bekerja bersama: cara
> menjalankan proyek, pembagian tugas, dan aturan yang wajib dipatuhi.

## Isi

| Berkas | Isi |
|---|---|
| [ikhtisar-sistem.md](ikhtisar-sistem.md) | Tujuan sistem, peran pengguna, pembagian sub sistem, tumpukan teknologi |
| [progres.md](progres.md) | Catatan kronologis tiap tahap pengerjaan |
| [keputusan/](keputusan/) | Architecture Decision Record — alasan di balik tiap keputusan rancangan |
| [modul/](modul/) | Rincian tiap modul: fitur, rute, hak akses, aturan bisnis |
| [erd/penyelarasan-erd.md](erd/penyelarasan-erd.md) | Peta kepemilikan tabel dan agenda penyelarasan tabel bersama dengan Alma |

## Daftar keputusan (ADR)

| No | Judul | Status |
|---|---|---|
| [0001](keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md) | Autentikasi berbasis sesi tanpa starter kit | Diterima |
| [0002](keputusan/0002-otorisasi-peran-gate-dan-can.md) | Otorisasi: enum peran, gate, dan `can:` alih-alih `role:` | Diterima |
| [0003](keputusan/0003-arsip-alih-alih-hapus.md) | Akun dan barang diarsipkan, bukan dihapus | Diterima |
| [0004](keputusan/0004-stok-hanya-lewat-stockledger.md) | Stok hanya berubah lewat `StockLedger` | Diterima |
| [0005](keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md) | Buku mutasi immutable dan diurutkan menurut waktu pencatatan | Diterima |
| [0006](keputusan/0006-mutationtype-merangkap-alasan-manual.md) | `MutationType` merangkap alasan mutasi manual | Diterima |
| [0007](keputusan/0007-kolom-audit-dan-referensi-polimorfik.md) | Kolom audit tambahan dan referensi dokumen polimorfik | Diterima |
| [0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md) | Penerimaan barang dikonfirmasi Pengelola Gudang | Diterima |
| [0009](keputusan/0009-laporan-pdf-dan-csv.md) | Laporan PDF lewat dompdf dan CSV tanpa paket tambahan | Diterima |
| [0010](keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) | Alur stok opname: draf, posting wajib lengkap, dan penyesuaian hanya menerapkan selisih hasil cacah | Diterima |
| [0011](keputusan/0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md) | Alasan selisih: enum baku berpasangan keterangan wajib | Diterima |
| [0012](keputusan/0012-periode-opname-enum-jenis-dan-tahun.md) | Periode opname sebagai enum jenis + tahun | Diterima |

## Cara memelihara dokumentasi ini

| Pemicu | Tindakan |
|---|---|
| Keputusan rancangan baru | Tambah ADR bernomor urut berikutnya di `keputusan/` |
| Keputusan lama berubah | **Jangan sunting ADR lama.** Buat ADR baru, lalu ubah status ADR lama menjadi `Digantikan oleh 00XX` |
| Modul selesai atau berubah | Perbarui berkas terkait di `modul/` |
| Sesi kerja menghasilkan perubahan | Tambah satu entri di [progres.md](progres.md) |
| Skema database berubah | Catat pada ADR terkait dan [erd/penyelarasan-erd.md](erd/penyelarasan-erd.md) |
| Skema **tabel bersama** berubah | Selain dicatat, **kabari Alma** — lihat peta kepemilikan tabel |

**ADR lama tidak pernah disunting.** Alasannya: jejak perubahan cara berpikir
justru bernilai untuk naskah tugas akhir dan untuk menjawab pertanyaan sidang.
Menyunting ADR lama menghapus bukti bahwa keputusan itu pernah dipertimbangkan
ulang.

### Format ADR

Tiap ADR memuat lima bagian tetap:

1. **Status** — Diterima / Digantikan oleh 00XX
2. **Konteks** — keadaan yang membuat keputusan ini perlu diambil
3. **Keputusan** — apa yang diputuskan
4. **Alternatif yang ditolak** — beserta alasan penolakannya
5. **Konsekuensi** — akibat yang harus diterima, baik menguntungkan maupun tidak
