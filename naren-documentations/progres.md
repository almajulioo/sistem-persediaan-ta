# Catatan Progres

Urut dari yang terbaru. Satu entri untuk tiap sesi kerja yang menghasilkan
perubahan.

---

## 3 September 2026 — Dokumentasi dipisah per pengembang

**Latar belakang.** Narendra dan Alma menulis **skripsi terpisah dengan judul
berbeda**, masing-masing tentang sub sistemnya sendiri. Konsekuensinya ERD
keduanya pun berbeda — dan itu **memang benar**, bukan kesalahan yang harus
disatukan menjadi satu gambar.

Framing sebelumnya keliru: dokumentasi memperlakukan perbedaan ERD sebagai
selisih yang harus diperbaiki seluruhnya.

**Yang dikerjakan.**

- Folder `documentations/` diganti nama menjadi `naren-documentations/`, karena
  Alma memelihara dokumentasinya sendiri.
- Sebutan "rekan" diganti nama sebenarnya, **Alma**, di seluruh dokumentasi.
- `erd/penyelarasan-erd.md` ditulis ulang: dari "selisih ERD yang harus
  diperbaiki" menjadi **peta kepemilikan tabel**. Kini memuat empat kelompok —
  tabel bersama (wajib identik), milik Naren, milik Alma, dan perbatasan yang
  perlu kesepakatan. Keempat temuan lama tetap ada, tetapi dikelompokkan menurut
  siapa yang berhak memperbaikinya.
- `README.md` root menegaskan bahwa ERD berbeda itu wajar, dan menyebut keempat
  tabel bersama beserta aturannya.

**Catatan.** Temuan #1 (`request_date` pada `procurement_requests`) ternyata milik
Naren sendiri, jadi dapat dikerjakan tanpa menunggu kesepakatan. Temuan #2 dan #3
berada di perbatasan, #4 milik Alma.

---

## 3 September 2026 — Koreksi semantik riwayat mutasi + dokumentasi proyek

**Latar belakang.** Klarifikasi bersama pengguna menemukan bahwa data contoh dan
sebagian semantik modul mutasi menanamkan **asumsi bisnis yang salah**. Berbahaya
justru karena tidak terlihat sebagai bug: sistemnya jalan, tetapi mengajarkan
proses yang keliru.

Tiga hal yang terbukti keliru saat diperiksa terhadap data nyata:

| Temuan | Bukti |
|---|---|
| Nomor referensi `PG01001`/`PR01001` tidak menunjuk apa pun | `reference` terisi 0 dari 77 baris |
| Keterangan "pengadaan **triwulan**" | Kadensi yang tidak pernah disebutkan, dikarang seeder |
| Atribusi petugas Barang Masuk tanpa dasar | Dipilih tanpa alasan yang dinyatakan |

**Yang dikerjakan.**

- Aturan proses baru: penerimaan barang dikonfirmasi **Pengelola Gudang**, bukan
  Pejabat Pengadaan — pemisahan tugas ([ADR 0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md)).
  Ini menentukan rancangan modul Pengadaan: realisasi berhenti di status
  *Menunggu Penerimaan*.
- Kolom `reference` diganti `reference_id`, menjadi relasi polimorfik Laravel
  dengan morph map ([ADR 0007](keputusan/0007-kolom-audit-dan-referensi-polimorfik.md)).
  Parameter `$referenceType` dihapus dari `StockLedger::record()`; jenis dokumen
  diturunkan dari `getMorphClass()`.
- Kolom **Sumber** ditambahkan di layar, PDF, dan CSV.
- Data contoh diperbaiki: kata "triwulan" dan kode karangan dihapus, keterangan
  dirakit dari data dokumen yang realistis.
- Folder `documentations/` dibuat beserta 9 ADR, 4 dokumen modul, ikhtisar
  sistem, dan catatan progres ini. `README.md` root dirampingkan menjadi hanya
  berisi hal yang diperlukan untuk pengembangan bersama.

**Bug yang ditemukan dan diperbaiki dalam sesi ini.** Seeder menulis
`reference_type` ke baris yang **salah**: seluruh baris dibuat pada detik yang
sama sehingga `latest('created_at')` mengembalikan baris sembarang — ternyata
mengenai `saldo_awal`. Akibatnya 14 baris saldo awal salah berlabel Permintaan,
dan 44 baris jatuh ke Sumber "Sistem". Diperbaiki dengan memakai objek yang
dikembalikan `StockLedger::record()` alih-alih mencarinya ulang lewat query.

**Verifikasi.** 111 test lulus. Sebaran Sumber setelah perbaikan: Pengadaan 15,
Permintaan 30, Manual 15, Saldo Awal 14. Tidak ada lagi `saldo_awal` yang punya
`reference_type`, dan tidak ada `masuk`/`keluar` tanpa `reference_type`.

**Catatan.** Satu kegagalan test transien pernah teramati satu kali dan tidak
dapat direproduksi dalam 4 kali percobaan berikutnya; penyebabnya belum
ditemukan.

---

## 3 September 2026 — Modul Mutasi Stok & Riwayat Mutasi

Dibangun sesuai rancangan low-fi "Riwayat Mutasi", dengan tujuh penyempurnaan:
jumlah bertanda berwarna, kolom Stok Setelah, kolom Petugas, referensi terbaca,
penyaring diperluas, kartu rekap, dan sifat immutable.

**Yang dikerjakan.**

- `MutationType` diperluas dari 4 menjadi 10 case, merangkap alasan mutasi manual
  ([ADR 0006](keputusan/0006-mutationtype-merangkap-alasan-manual.md)).
- Empat kolom audit ditambahkan ke `stock_mutations`: `user_id`, `stock_after`,
  `reference_number`, `notes` — dengan pengisian ulang `stock_after` untuk baris
  lama.
- Formulir mutasi manual dengan enam alasan baku, keterangan wajib, dan penolakan
  jenis milik dokumen.
- Laporan PDF (dompdf), ekspor CSV, dan Kartu Persediaan per barang
  ([ADR 0009](keputusan/0009-laporan-pdf-dan-csv.md)).
- Buku mutasi diurutkan menurut waktu pencatatan, bukan tanggal transaksi
  ([ADR 0005](keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)).

**Temuan penting.** Formulir mengizinkan tanggal mundur, sehingga urutan tanggal
tidak selalu sama dengan urutan pencatatan. Karena `stock_after` adalah saldo
pada saat mutasi dicatat, buku mutasi harus dibaca dalam urutan pencatatan.

**Verifikasi.** 107 test lulus. Uji HTTP nyata: PDF 880 KB diawali `%PDF-1.7`;
mutasi manual menurunkan stok dengan benar; jenis `masuk` ditolak dari formulir
manual; stok kurang memunculkan pesan validasi yang ramah.

**Catatan.** Pint sempat memformat ulang 9 model dan 13 migrasi warisan ERD
karena dijalankan pada direktori terlalu luas — sudah dikembalikan. Untuk
repositori berdua, reformat berkas bersama hanya memicu konflik merge.

---

## 3 September 2026 — Modul Master Barang

Inti sub sistem gudang, karena seluruh modul lain memiliki foreign key ke
`items`.

**Yang dikerjakan.**

- `StockLedger` sebagai satu-satunya jalur perubahan stok, dengan penguncian
  baris dan penolakan stok negatif
  ([ADR 0004](keputusan/0004-stok-hanya-lewat-stockledger.md)).
- Enum `SatuanBarang`, `MutationType`, `StockStatus`.
- CRUD master barang dengan pencarian, tiga penyaring, ringkasan, dan halaman
  detail berisi riwayat mutasi.
- Kolom `is_active` pada `items` — barang bertransaksi diarsipkan, bukan dihapus
  ([ADR 0003](keputusan/0003-arsip-alih-alih-hapus.md)).
- 15 barang ATK/RTK contoh.

**Temuan penting.** Middleware `role:pengelola_gudang` akan **menolak Admin**,
karena `EnsureUserHasRole` memeriksa peran secara harfiah tanpa bypass. Rute
gudang karena itu memakai `can:kelola-gudang`
([ADR 0002](keputusan/0002-otorisasi-peran-gate-dan-can.md)).

**Verifikasi.** 75 test lulus. Uji HTTP: kode barang dinormalkan ke huruf besar;
`stock_current=9999` yang diselundupkan ke request tidak mengubah stok; barang
bermutasi menolak dihapus; `pengadaan` menerima 403, `admin` menerima 200.

---

## 1–2 September 2026 — Manajemen Pengguna

CRUD akun khusus Admin. Karena sistem tertutup tanpa pendaftaran mandiri, modul
inilah satu-satunya cara akun bertambah.

**Yang dikerjakan.**

- CRUD lengkap dengan pencarian, penyaring peran dan status, ringkasan, serta
  pengaktifan/penonaktifan cepat.
- Penjaga: Admin tidak dapat mengubah peran, menonaktifkan, atau menghapus
  akunnya sendiri; akun bertransaksi tidak dapat dihapus.
- Komponen bersama lahir di sini dan dipakai ulang seluruh modul berikutnya:
  `x-form.text-field`, `x-form.select-field`, `x-form.password-field`,
  `x-ui.flash`, dan paginasi bergaya TailAdmin.

**Catatan.** Aturan `alpha_dash` diganti regex sendiri agar pola `budi.santoso`
dapat dipakai. Penjaga "Admin aktif terakhir" ditulis lalu dihapus karena tidak
pernah dapat tereksekusi.

**Verifikasi.** 42 test lulus. Uji HTTP: buat, ubah, toggle, hapus semua bekerja;
`gudang` menerima 403 dan menunya tidak muncul.

---

## 1 September 2026 — Autentikasi & Otorisasi

Fondasi sistem: masuk, keluar, peran, dan penjagaan rute.

**Yang dikerjakan.**

- Autentikasi sesi Laravel tanpa starter kit, disesuaikan dengan skema `users`
  dari ERD ([ADR 0001](keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md)).
- Enum `UserRole` tiga peran, gate berbasis peran dengan `Gate::before` untuk
  Admin, middleware `role:` dan `EnsureUserIsActive`.
- Halaman masuk berbahasa Indonesia tanpa tombol sosial dan tanpa pendaftaran
  mandiri; dasbor sementara dengan modul yang tersaring peran.
- Kolom `last_login_at` dan `last_login_ip`; jejak masuk dan keluar ke
  `audit_logs`.
- Tiga akun awal: `admin`, `gudang`, `pengadaan`.

**Catatan.** Halaman `signin` dan `signup` bawaan template dihapus. Zona waktu
aplikasi disetel `Asia/Jakarta` dan locale `id`.

**Verifikasi.** 20 test lulus. Uji HTTP: login/logout, penguncian setelah lima
percobaan gagal, akun nonaktif ditendang di tengah sesi.
