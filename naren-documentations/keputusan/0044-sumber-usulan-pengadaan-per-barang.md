# ADR 0044 — Sumber usulan pengadaan dicatat per barang

## Status

Diterima — 8 November 2026

Menggantikan sebagian [ADR 0015](0015-usulan-pengadaan-dan-penerimaan-barang.md)
(kolom `source`, `reference_type`, `reference_id` pada `procurement_requests`,
termasuk sumber `DariPermintaan`) dan
[ADR 0027](0027-draf-usulan-pengadaan-otomatis.md) (draf bersumber
`PemantauanStok`; `is_auto` tidak lagi merangkap asal baris).

## Konteks

ADR 0015 menyimpan sumber usulan **per dokumen**: `procurement_requests.source`
bernilai `dari_permintaan`, `pemantauan_stok`, atau `manual`. Itu benar
selama satu dokumen hanya lahir dari satu jalur.

Asumsi itu runtuh dua kali:

1. **ADR 0027** menjadikan draf otomatis satu dokumen tunggal yang bisa
   disunting Pengelola Gudang — barang tambahannya ikut masuk dokumen yang
   sama, tetap berlabel `pemantauan_stok`.
2. **Permohonan pengguna** (sub sistem Permintaan, Alma, 30 Oktober 2026)
   meneruskan baris tambah stok yang disetujui ke draf yang sama. Alma
   menambahkan sumber `dari_permohonan`, tetapi karena sumbernya per dokumen,
   draf mendapat sumber **siapa pun yang kebetulan membuatnya lebih dulu**.
   Draf yang dibuat permohonan lalu diisi pemindaian stok tetap berlabel
   "Permohonan Pengguna" untuk seluruh barangnya, dan sebaliknya.

Akibatnya penyaring "Sumber Usulan" di daftar usulan menyaring sesuatu yang
tidak lagi bermakna. Halaman draf sudah menebak sumber per barang dari
`is_auto` dan tautan permohonan, tetapi tebakan itu salah dengan cara yang
nyata: menyimpan draf menandai **seluruh** baris `is_auto = false` (ADR 0027),
sehingga setiap barang pemantauan stok langsung terbaca "Manual" begitu
Pengelola Gudang mengubah satu angka.

Narendra meminta penyaring sumber dibuang, sumber dipindah ke tiap barang
dengan tiga nilai — permohonan pengguna, manual, pemantauan stok otomatis —
dan barang manual diberi keterangan alasan yang opsional, tanpa terasa
repetitif saat mengisinya.

## Keputusan

### Sumber dan keterangan pindah ke `procurement_request_details`

- `procurement_request_details.source` (string, enum
  `ProcurementRequestSource`, **NOT NULL tanpa nilai bawaan**) dan `note`
  (text, nullable).
- `ProcurementRequestSource` tinggal tiga kasus: `DariPermohonan`
  ("Permohonan Pengguna"), `Manual`, `PemantauanStok` ("Pemantauan Stok
  Otomatis"), masing-masing dengan `warna()`/`icon()` mengikuti pola
  `App\Support\Warna`.
- `procurement_requests.source`, `reference_type`, `reference_id` **dibuang**.
  Referensi polimorfik itu hanya bermakna untuk `dari_permintaan`, tidak
  pernah diisi kode mana pun, dan asal permohonan sudah ditunjuk per barang
  oleh `item_application_details.procurement_request_id` milik Alma.

Tanpa nilai bawaan dengan alasan yang sama dengan `audit_logs.module`
(ADR 0034): setiap jalur pembuat baris wajib menyebut asalnya sendiri, dan
yang lupa gagal keras, bukan diam-diam menjadi "manual".

### Siapa yang menetapkan sumber

Sumber **tidak pernah diterima dari kiriman formulir**:

| Jalur | Sumber |
|---|---|
| `ProcurementDraftScanner` | `PemantauanStok` |
| Persetujuan permohonan (`ItemApplicationWorkflowService`) | `DariPermohonan` — juga pada baris yang sudah ada |
| Formulir usulan manual | `Manual` |
| Barang baru yang ditambahkan di halaman draf | `Manual` |
| Baris draf yang sudah ada, disunting jumlahnya | sumber lamanya dipertahankan |

Permohonan yang menumpuk ke baris yang sudah ada **mengambil alih sumbernya**.
Satu barang hanya punya satu sumber, dan kebutuhan pengguna yang tertaut
dokumen adalah alasan terkuat; tautan per permohonan tetap ditampilkan
sehingga jumlah yang lebih besar dari total permohonan tetap terbaca.
Keterangan baris manual yang sudah ada tidak ikut dihapus.

`is_auto` kembali ke satu makna saja: baris draf yang masih dikelola
pemindaian. Formulir manual kini mengisinya `false`.

### Keterangan hanya untuk barang manual, dan tidak repetitif

`ProcurementRequestSource::bolehBerketerangan()` hanya benar untuk `Manual`:
dua sumber lain sudah membawa alasannya sendiri (dokumen permohonan, stok di
bawah minimum). Server mengabaikan `note` pada baris non-manual.

Formulir manual dan halaman draf memakai satu komponen,
`x-pengadaan.editor-barang`, dengan tiga cara menghindari mengetik ulang:

1. **Keterangan menempel.** Setelah barang ditambahkan, isian keterangan
   tidak dikosongkan — beberapa barang yang diusulkan bersamaan biasanya
   punya alasan sama. Tombol × mengosongkannya.
2. **Saran sekali klik.** Hingga tiga keterangan yang sudah dipakai di daftar
   muncul sebagai chip.
3. **"Pakai untuk N barang tanpa keterangan".** Untuk alasan yang baru
   terpikir setelah barangnya ditambahkan.

Keterangan tiap baris juga dapat disunting di tempat. Pemilih barangnya
memakai ulang `x-request.pemilih-barang` milik Alma (penyaring kategori,
navigasi papan ketik, 50 baris pertama), menggantikan daftar tarik-turun lama
yang merender seluruh 1.801 barang.

### Tampilan

- **Daftar usulan**: penyaring dan kolom Sumber dibuang. Banner draf
  menampilkan jumlah barang per sumber.
- **Detail usulan**: kolom Sumber per barang (pill berikon, tautan
  permohonan, keterangan manual), garis kiri berwarna sumber, dan chip
  penyaring sumber bila usulannya bercampur. Baris yang disembunyikan
  penyaring tetap terkirim saat Pejabat Pengadaan menyetujui.
- **Draf**: pill sumber per barang, chip penyaring yang sama, jumlah dapat
  diubah dengan stepper, dan "Ajukan Usulan Ini" nonaktif selama ada
  perubahan yang belum disimpan.

### Data lama

Migrasi `2026_11_08_000001` mengisi sumber baris lama dengan urutan aturan,
yang pertama cocok menang:

1. ditunjuk baris permohonan → `dari_permohonan`;
2. dokumen bersumber `manual` → `manual` (formulir lama tidak pernah mengisi
   `is_auto`, sehingga barisnya ikut bernilai bawaan `true`);
3. `is_auto = true` → `pemantauan_stok`;
4. dokumen `dari_permintaan` → `dari_permohonan` (jalur lama kekurangan stok
   permintaan, pendahulu permohonan);
5. dokumen `pemantauan_stok` → `pemantauan_stok`;
6. sisanya → `manual`.

`down()` mengembalikan sumber dokumen dari barisnya dengan urutan kekuatan
permohonan > manual > pemantauan stok.

## Alternatif yang ditolak

**Mempertahankan sumber dokumen sebagai "Campuran".** Menambah satu nilai
yang tidak menjelaskan apa pun, dan penyaringnya tetap tidak berguna.

**Satu baris per sumber untuk barang yang sama.** Barang yang dibutuhkan
pemohon dan juga di bawah minimum akan muncul dua kali. Melanggar "satu
barang satu baris" yang dijaga validasi `distinct`, persetujuan per barang,
dan cakupan realisasi (ADR 0021).

**Menurunkan sumber dari `is_auto` dan tautan permohonan**, seperti yang
sudah dilakukan halaman draf. Justru inilah cacatnya: `is_auto` menjawab "masih
dikelola pemindaian?", bukan "dari mana asalnya?", dan menyimpan draf
mengubah jawaban pertama tanpa mengubah yang kedua.

**Keterangan untuk semua sumber.** Permohonan sudah membawa keperluan
pemohon, pemantauan stok sudah membawa angka stoknya. Kolom kosong di dua
pertiga baris hanya menambah isian yang tidak perlu.

**Satu keterangan per dokumen** untuk usulan manual. Lebih sedikit mengetik,
tetapi alasan satu barang dan barang lain dalam usulan yang sama sering
berbeda; keterangan yang menempel memberi kecepatan yang sama tanpa
kehilangan rincian.

**Daftar alasan baku sebagai pilihan cepat** (mis. dari `AlasanPengadaan`).
Enum itu dibuat untuk realisasi tanpa usulan; mengarang daftar baru tanpa data
lapangan berisiko tidak cocok. Saran dari keterangan yang sudah diketik di
daftar yang sama tidak membutuhkan tebakan.

## Konsekuensi

- **Berkas Alma ikut disentuh, sesempit mungkin**:
  `ItemApplicationWorkflowService::addToProcurementDraft()` kini mengisi
  `source` per baris alih-alih per draf, dan `PermohonanTest` disesuaikan:
  sumber diperiksa pada baris, bukan dokumen; satu fixture draf membawa sumber
  per baris; dan pemeriksaan penyaring `?source=dari_permohonan` diganti
  pemeriksaan label sumber di detail usulan. Perlu dikabarkan ke Alma.
- Komponen `x-request.pemilih-barang` milik Alma kini juga dipakai halaman
  pengadaan. Mengubah antarmukanya berarti memeriksa dua halaman ini juga.
- Notifikasi `DraftUsulanTersedia` tidak lagi menyebut "di bawah stok
  minimum", karena barang draf kini bisa datang dari permohonan atau tambahan
  manual.
- Rincian usulan contoh di seeder kini membawa sumber per barang, walaupun
  masih lahir kosong (kode `ATK-0xx` yang hilang, butir terbuka sejak
  24 Oktober).
- Rekap "berapa barang dari pemantauan stok vs permohonan" kini dapat dihitung
  langsung dari satu kolom bila kelak dibutuhkan laporan.
