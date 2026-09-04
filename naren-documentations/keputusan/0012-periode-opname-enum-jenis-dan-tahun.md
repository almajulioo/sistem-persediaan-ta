# ADR 0012 — Periode opname sebagai enum jenis + tahun

## Status

Diterima — 4 September 2026

## Konteks

Keluaran modul ini adalah **Laporan Stok Opname per periode**, dan periode yang
disebutkan pengguna adalah triwulan. ERD awal `stock_opnames` hanya memuat
`opname_date`, sehingga periode harus disimpulkan dari tanggal.

Menyimpulkan periode dari tanggal terlihat masuk akal sampai dihadapkan pada
kasus nyata: opname Triwulan IV yang baru dikerjakan awal Januari akan terbaca
sebagai Triwulan I tahun berikutnya. Yang menentukan periode adalah **periode
yang dipertanggungjawabkan**, bukan hari petugas kebetulan turun ke gudang.

Selain itu tidak semua opname terikat jadwal. Ada pencacahan mendadak — misalnya
setelah kejadian tertentu, atau atas permintaan pemeriksa — yang tidak dapat
dipaksa masuk ke salah satu triwulan.

## Keputusan

Periode disimpan sebagai **dua kolom**: `period_type` (enum) dan `period_year`
(unsigned smallint), terpisah dari `opname_date`.

`app/Enums/PeriodeOpname.php` memuat delapan case: `TriwulanI`–`TriwulanIV`,
`SemesterI`–`SemesterII`, `Tahunan`, dan `Insidental`. Case `Insidental`
menampung pencacahan di luar jadwal rutin tanpa memaksanya berbohong tentang
triwulan.

Judul dokumen terbentuk sendiri dari keduanya lewat `StockOpname::judul()`:

```
Stok Opname Triwulan III 2026
```

Judul yang sama dipakai tiga kali — sebagai judul di layar, sebagai judul
laporan PDF, dan sebagai `reference_number` pada mutasi penyesuaian yang
dihasilkan. Dengan begitu baris di riwayat mutasi menunjuk balik ke dokumen
asalnya dengan nama yang dapat dibaca manusia, melengkapi relasi polimorfik
`reference` ([ADR 0007](0007-kolom-audit-dan-referensi-polimorfik.md)).

Indeks gabungan `(period_year, period_type)` mendukung penyaringan daftar
opname.

`opname_date` tetap ada dan tetap bermakna: tanggal pencacahan fisik dilakukan.
Ia juga menjadi `mutation_date` pada mutasi penyesuaian, sehingga penyesuaian
tercatat pada tanggal barang benar-benar dihitung.

## Alternatif yang ditolak

**Menyimpulkan periode dari `opname_date`.** Tidak menambah kolom sama sekali,
tetapi salah pada kasus yang justru paling sering terjadi: opname akhir periode
hampir selalu dikerjakan setelah periodenya lewat. Juga tidak menyediakan tempat
bagi opname insidental.

**Satu kolom string bebas, misalnya "Triwulan III 2026".** Tidak dapat disaring
per tahun, tidak dapat diurutkan, dan rawan salah ketik yang memecah data yang
sebenarnya sama.

**Kolom `period_start` dan `period_end` bertipe tanggal.** Lebih luwes dan lebih
umum, tetapi rentang tanggal tidak menjawab pertanyaan yang benar-benar diajukan
pengguna — opname triwulan keberapa ini. Nama periode harus disimpulkan balik
dari rentangnya, dan dua opname triwulan yang sama dapat memiliki rentang yang
berbeda sedikit.

**Tabel `periods` tersendiri.** Menormalkan periode menjadi baris data sehingga
dapat ditambah tanpa mengubah kode. Ditolak karena daftar periode sangat stabil
dan jumlahnya delapan; satu tabel, satu foreign key, dan satu join untuk itu
tidak sepadan.

## Konsekuensi

- Petugas memilih periode secara sadar saat membuat draf, dan tidak dapat lalai
  karena kolomnya wajib.
- Dua opname pada periode dan tahun yang sama **tidak dilarang**. Disengaja:
  opname ulang atas periode yang sama bisa saja diperlukan, dan larangan basis
  data akan menghalangi tanpa memberi jalan keluar.
- `Insidental` dapat berulang dalam satu tahun dan judulnya tidak membedakan
  satu dari yang lain. Bila ini kelak mengganggu, nomor dokumen resmi adalah
  jawabannya — lihat butir terbuka pada
  [penyelarasan-erd.md](../erd/penyelarasan-erd.md).
- Menambah jenis periode berarti menambah case enum. Sama seperti
  [ADR 0011](0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md), dapat
  diterima karena daftarnya jarang berubah.
