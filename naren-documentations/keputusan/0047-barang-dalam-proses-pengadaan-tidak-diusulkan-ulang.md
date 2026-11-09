# ADR 0047 — Barang yang sedang dalam proses pengadaan tidak diusulkan ulang

## Status

Diterima — 8 November 2026

Menggantikan sebagian [ADR 0027](0027-draf-usulan-pengadaan-otomatis.md):
pemindaian stok tidak lagi memasukkan **setiap** barang di bawah minimum ke
draf. Barang yang sudah punya pengadaan berjalan dilewati sampai siklus itu
selesai.

## Konteks

Narendra melaporkan urutan berikut:

1. Stok sebuah barang habis.
2. Barang itu masuk draf usulan pengadaan.
3. Gudang mengajukan drafnya menjadi usulan.
4. Permohonan pengguna yang disetujui masuk ke draf usulan baru.
5. Draf baru itu ternyata memuat lagi barang habis tadi, padahal barang itu
   sudah diusulkan dan usulannya belum selesai.

Penyebabnya ada di `ProcurementDraftScanner`. Pemindaian hanya menanyakan satu
hal per barang, yaitu `stock_current <= stock_min`. Ia tidak pernah melihat
apakah barang itu sudah ada di usulan atau realisasi yang masih berjalan.
Begitu draf diajukan, pemindaian berikutnya mana pun akan memasukkannya lagi:
membuka halaman draf (`ProcurementDraftController::edit()` memanggil
`perbaruiSeluruh()`), sapuan pagi `notifikasi:periksa`, atau mutasi apa pun
atas barang itu.

Jadi ini **bukan akibat alur permohonan Alma**. Persoalannya sudah ada sejak
ADR 0027. Permohonan hanya membuatnya lebih cepat terlihat, karena ia
melahirkan draf baru yang kemudian dibuka.

Ada pula selisih dengan ADR 0027 sendiri. Bagian "Barang yang dibuang manual
bisa muncul lagi" menyebut salah satu cara barang berhenti muncul adalah
"memasukkannya ke usulan yang benar-benar diajukan". Kodenya tidak pernah
melakukan itu.

Akibatnya di lapangan: kekurangan yang sama diusulkan dua kali, dan kalau
keduanya disetujui, barangnya terbeli dua kali.

## Keputusan

### 1. Definisi "dalam proses pengadaan"

| Keadaan | Dalam proses? |
|---|---|
| Usulan `Diajukan` (belum diputus) | Ya |
| Usulan `DisetujuiMenungguRealisasi`, barang dengan `amount_granted > 0` | Ya |
| Barang yang tidak disetujui (`amount_granted = 0`), atau usulan `Ditolak` | **Tidak**, langsung lepas |
| Rincian realisasi yang `masihBerjalan()`: MenungguPersetujuan, **Ditolak**, ProsesBelanja, MenungguPenerimaan | Ya |
| Realisasi `Dibatalkan` | **Tidak**, langsung lepas |
| Realisasi `Diterima` | **Tidak**. Bila barangnya datang kurang (ADR 0017) dan stok masih di bawah minimum, barang muncul lagi dengan saran baru |

Rincian **realisasi** ikut dihitung, bukan hanya rincian usulan. Sejak
[ADR 0029](0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md)
(barang tambahan) dan
[ADR 0031](0031-realisasi-pengadaan-tanpa-usulan.md) (realisasi tanpa usulan),
barang bisa dibeli tanpa pernah tercantum di usulan mana pun.

Realisasi yang **ditolak Kasubbag** tetap dihitung dalam proses. Penolakan itu
tidak terminal ([ADR 0030](0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md)):
dokumennya masih bisa diperbaiki lalu diajukan ulang. Kalau barangnya
dilepas di titik itu, dobel lagi begitu perbaikannya disetujui. Berbeda dari
penolakan **usulan** oleh Pejabat Pengadaan, yang memang mengakhiri
siklusnya.

Status yang dihitung untuk realisasi diambil dari
`ProcurementStatus::berjalan()`, yang diturunkan dari `masihBerjalan()`.
Dengan begitu keduanya tidak pernah menyimpang.

### 2. Dihitung langsung dari dokumen, bukan tanda tersimpan

`ProcurementDraftScanner::dokumenDalamProses()` membaca status dokumen setiap
kali dipanggil. Tidak ada kolom baru, dan tidak ada migrasi. Pembatalan,
penolakan, dan penerimaan melepaskan barang dengan sendirinya, tanpa ada tanda
yang harus diingat untuk dibersihkan.

Satu definisi itu dipakai di dua tempat: pemindaian, dan bagian halaman draf
di butir 5. Barang yang dilewati pemindaian karena itu selalu tepat barang
yang ditampilkan, sejalan dengan prinsip angka yang sama di
[ADR 0040](0040-dasbor-berbasis-peran-dari-data-langsung.md).

### 3. Hanya baris otomatis

Aturan ini hanya mengatur baris `is_auto` (sumber `pemantauan_stok`). Baris
dari permohonan pengguna dan baris yang ditambahkan Gudang sendiri tetap
masuk dan tetap dipertahankan seperti biasa, sesuai aturan ADR 0027 "sekali
disimpan manusia, selamanya milik manusia". Kebutuhan pengguna yang tertaut
ke dokumen adalah kebutuhan tersendiri, bukan pengulangan kekurangan stok.

Baris manual atas barang yang sedang dalam proses **tidak diberi penanda apa
pun**. Ini permintaan eksplisit Narendra.

### 4. Barang yang ditolak Pejabat Pengadaan langsung muncul lagi

Ini juga keputusan Narendra, dan sejalan dengan filosofi ADR 0027: kekurangan
stoknya belum selesai, jadi memunculkannya lagi adalah perilaku yang benar.
Gudang tetap bebas membuangnya dari draf.

### 5. Pemicu dari dokumen pengadaan

Barang bisa masuk atau keluar dari "dalam proses" tanpa stoknya berubah. Jadi
pemicu dari perubahan stok ([ADR 0014](0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md))
saja tidak cukup. Metode baru `ProcurementDraftScanner::perbaruiBarangDokumen()`
menjalankan `perbaruiBarang()` untuk setiap barang pada dokumen, dan
dipanggil eksplisit di luar transaksi di titik-titik berikut:

| Titik | Akibat bagi barangnya |
|---|---|
| `ProcurementRequestController::store()` (usulan manual) | Masuk proses, baris otomatisnya dibuang |
| `ProcurementRequestController::approve()` | Barang dengan jumlah disetujui 0 lepas |
| `ProcurementRequestController::reject()` | Seluruh barang lepas |
| `ProcurementController::store()` | Barang tambahan dan barang realisasi langsung masuk proses |
| `ProcurementController::update()` | Barang lama **dan** baru diperiksa: tambahan yang dihapus lepas, yang ditambahkan masuk |
| `ProcurementController::cancel()` | Seluruh barang lepas |
| `ProcurementReceiptPoster::post()` | Siklus selesai, dan stoknya baru saja berubah |

Titik-titik berikut sengaja **tidak** memanggilnya, karena himpunan barang
dalam proses tidak berubah di sana:

- mengajukan draf: draf itu sendiri yang menjadi usulan, dan tidak ada draf
  lain;
- menyetujui atau menolak realisasi;
- menandai realisasi sudah dibelanjakan.

### 6. Bagian "Sedang dalam Proses Pengadaan" di halaman draf

Halaman draf mendapat satu bagian yang bisa dilipat, tertutup secara default,
dengan judul dan jumlah barang. Isinya barang aktif di bawah minimum yang
dilewati pemindaian: nama, kode, stok, dan tautan ke setiap dokumen yang
sedang memprosesnya beserta lencana statusnya.

Bagian ini tetap tampil walau tidak ada draf, karena justru saat itulah
paling dibutuhkan. Tanpa bagian ini, Gudang akan mengira barang yang habis
terlewat lalu menambahkannya manual, dan pengadaannya dobel lagi. Bagian ini
hanya berisi data, sesuai aturan UI bersih.

## Alternatif yang ditolak

**Posisi persediaan.** Saran jumlah dihitung sebagai
`sasaran − (stok + jumlah yang sedang dipesan)`, dan barang hanya muncul bila
hasilnya masih kurang. Ini konsep baku manajemen persediaan (*inventory
position*), dan menangani konsumsi selama barang dipesan. Ditolak karena dua
hal:

- Ia melawan keputusan persetujuan sebagian. Kalau Pejabat Pengadaan sengaja
  menyetujui 5 dari 40, sistem langsung mengusulkan ulang sisanya.
- Sarannya sulit dibaca tanpa kolom tambahan. Misalnya "kenapa sarannya cuma
  12, padahal stoknya 0?"

Narendra memilih aturan biner: barang dilewati selama prosesnya berjalan.

**Tanda tersimpan pada barang**, misalnya kolom `items.dalam_proses`. Ditolak
karena tandanya pasti basi saat realisasi dibatalkan atau usulan ditolak, dan
`items` adalah tabel bersama. Pelajarannya sama dengan `resolved_at` di
[ADR 0036](0036-lonceng-kotak-masuk-dan-notifikasi-selesai-dihapus.md).

**Hanya memeriksa rincian usulan.** Lebih sederhana, tetapi melewatkan barang
tambahan dan realisasi langsung, sehingga barang yang sudah dibeli tetap
diusulkan.

**Penanda pada baris manual atau permohonan** atas barang yang sedang dalam
proses. Ditolak oleh Narendra. Daftar di bagian halaman draf sudah cukup
memberi tahu.

**Memblokir penambahan manual** atas barang yang sedang dalam proses. Ditolak:
Gudang bisa punya alasan sah, misalnya kebutuhan mendadak melebihi yang
sedang dipesan. Keputusan seperti itu wewenang manusia, sama seperti di
ADR 0027.

**Masa tenang setelah ditolak**, yaitu barang yang ditolak Pejabat Pengadaan
baru muncul lagi setelah beberapa waktu. Ditolak oleh Narendra (butir 4).

## Konsekuensi

- Harga aturan biner: kalau sebuah barang sedang dalam proses hanya karena
  permohonan kecil, kebutuhan isi ulang stoknya baru muncul setelah siklus
  itu selesai. Diterima.
- Barang yang tersangkut di usulan atau realisasi berumur panjang ikut
  tersembunyi selama itu pula. Pengingat yang sudah ada menagih dokumen
  seperti itu (`UsulanMenungguPersetujuan`, `UsulanBelumDirealisasi`,
  `RealisasiBelumDibelanjakan`), dan bagian halaman draf menampilkannya.
- Notifikasi `DraftUsulanTersedia` kini menghitung lebih sedikit barang,
  karena barang yang sedang dalam proses tidak lagi ikut terhitung.
- Notifikasi `StokHabis`/`StokMenipis` tidak berubah, karena stok fisiknya
  memang masih kurang. Barang yang sudah diurus karena itu masih diingatkan
  ulang setiap hari bila Mendesak (ADR 0037). Memakai konsep "dalam proses"
  yang sama di sana menjadi keputusan terpisah.
- Tidak ada perubahan skema dan tidak ada tabel bersama yang berubah. Kode
  Alma (`ItemApplicationWorkflowService::addToProcurementDraft()`) tidak
  diubah, karena baris permohonan memang tidak diatur di sini.
- Modul baru yang membuat, memutus, atau membatalkan dokumen pengadaan wajib
  memanggil `perbaruiBarangDokumen()`. Kewajiban ini menambah, tidak
  menggantikan, kewajiban memanggil `perbaruiBarang()` saat stok berubah.
- **Temuan sampingan:** sebelum keputusan ini, penerimaan barang tidak
  memanggil scanner apa pun. Draf baru menyesuaikan diri saat halaman draf
  dibuka atau saat sapuan pagi. Pemindaian draf kini dipanggil di
  `ProcurementReceiptPoster::post()`. `NotificationScanner::periksaBarang()`
  **masih belum** dipanggil di sana, jadi notifikasi stok barang yang baru
  diterima tetap menunggu sapuan pagi. Ini dicatat sebagai butir terbuka.
