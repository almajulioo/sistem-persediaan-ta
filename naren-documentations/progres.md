# Catatan Progres

Urut dari yang terbaru. Satu entri untuk tiap sesi kerja yang menghasilkan
perubahan.

---

## 4 September 2026 — Modul Stok Opname

Modul terakhir sub sistem manajemen gudang. Dibangun dari activity diagram yang
sudah dibuat, dengan empat perbaikan: dokumen laporan dibuat **setelah** posting
(pada diagram ia bercabang paralel sehingga jadi sebelum alasan dimasukkan),
tambahan keadaan **draf** karena pencacahan tidak selesai dalam satu duduk,
**periode terstruktur** karena laporan diminta per triwulan, dan penanganan
**stok yang bergerak saat opname berjalan** yang tidak disinggung diagram.

**Yang dikerjakan.**

- Tiga enum baru: `PeriodeOpname` (8 case), `AlasanSelisih` (6 case),
  `OpnameStatus`.
- Dua migrasi: kolom alur dan periode pada `stock_opnames`; kolom alasan dan
  jejak posting pada `opname_details`, sekaligus melonggarkan `stock_real` dan
  `difference` menjadi nullable agar draf dapat menyimpan barang yang belum
  dicacah.
- `app/Support/OpnamePoster.php` — penerapan hasil opname dalam satu transaksi
  berkunci, mendampingi `StockLedger` dan `AuditLogger`.
- Alur draf → selesai: draf boleh sebagian, posting wajib lengkap dan setiap
  selisih wajib beralasan, opname selesai bersifat final
  ([ADR 0010](keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md)).
- Alasan selisih memakai enum baku **berpasangan** keterangan bebas, keduanya
  wajib ([ADR 0011](keputusan/0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md)).
- Periode disimpan sebagai enum jenis + tahun, terpisah dari `opname_date`
  ([ADR 0012](keputusan/0012-periode-opname-enum-jenis-dan-tahun.md)).
- Empat halaman (`index`, `create`, `edit`, `show`) dan Laporan Stok Opname PDF
  lanskap, memakai ulang infrastruktur laporan modul mutasi.

**Temuan penting — rancangan yang sempat disetujui ternyata salah.** Rencana
awal menetapkan penyesuaian sebagai `stock_real − stock_at_posting`, yaitu
memaksa stok akhir sama dengan hasil cacah. Verifikasi manual lewat HTTP
membuktikannya keliru:

| Langkah | Stok |
|---|---|
| Stok tercatat saat draf dibuat | 132 |
| Hasil cacah — cocok, tidak ada kesalahan pencatatan | 132 |
| Setelah pencacahan, 20 unit dicatat rusak lewat mutasi manual | 112 |
| Posting dengan rumus lama: `132 − 112 = +20` | **132** |

Posting **membatalkan kerusakan yang sah tercatat**. Rumus itu memperlakukan
seluruh jarak antara hasil cacah dan stok terkini sebagai kesalahan pencatatan,
padahal sebagian di antaranya justru transaksi yang benar.

Diperbaiki menjadi: yang diterapkan adalah **`difference`**, selisih yang
ditemukan saat mencacah. Stok akhir = `stock_at_posting + difference`, sehingga
transaksi setelah pencacahan tidak ikut dibatalkan. `OpnamePoster`, controller,
`show.blade.php`, templat PDF, dan uji ikut disesuaikan; satu uji baru
ditambahkan khusus untuk kasus "pencatatan cocok tetapi stok berubah setelahnya".

**Bug lain yang ditemukan dan diperbaiki dalam sesi ini.**

- `UpdateOpnameCountRequest` memvalidasi sebelum penjaga "sudah diposting" di
  controller, sehingga opname selesai memunculkan pesan selisih yang menyesatkan.
  Diperbaiki dengan keluar lebih awal di `withValidator`.
- `StockOpnameSeeder` berbagi koleksi barang yang sudah basi antara dua
  methodnya, sehingga `stock_stored` pada draf tidak sama dengan stok
  sebenarnya. Tiap method kini mengambil barang segar.
- Dua uji bergantung pada urutan yang tidak deterministik
  (`details->first()` dan `latest('created_at')` pada baris sedetik) — diganti
  `keyBy('item_id')` dan pembacaan URL redirect.

**Verifikasi.** 142 test lulus (443 asersi). Pint bersih pada 31 berkas dalam
cakupan sempit. Uji HTTP: draf berisi seluruh 15 barang aktif dengan stok
tercatat yang benar; posting ditolak selama masih ada yang belum dicacah;
selisih tanpa alasan ditolak; PDF diawali `%PDF`; `pengadaan` menerima 403 dan
`admin` menerima 200.

**Catatan.** `stock_opnames` dan `opname_details` **bukan tabel bersama**,
sehingga seluruh perubahan skema di sesi ini tidak perlu kesepakatan dengan
Alma — cukup dicatat di
[penyelarasan-erd.md](erd/penyelarasan-erd.md) agar gambar ERD naskah Naren
diperbarui.

**Berikutnya.** Sub sistem manajemen gudang selesai. Modul Pengadaan
**wajib menyelaraskan tabel perbatasan lebih dulu bersama Alma**, dan wajib
menerapkan [ADR 0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md):
realisasi berhenti di status *Menunggu Penerimaan*, dan Pengelola Gudang yang
mengonfirmasi penerimaan sehingga stok bertambah.

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
