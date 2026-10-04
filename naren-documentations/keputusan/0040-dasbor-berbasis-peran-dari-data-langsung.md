# ADR 0040 — Dasbor berbasis peran, dihitung langsung dari data

## Status

Diterima — 5 Oktober 2026

## Konteks

Dasbor masih peninggalan awal proyek: satu metrik dummy ("Pengadaan Berjalan
= 3"), metrik permintaan yang query-nya keliru
(`whereNot('status', 'rejected')->orWhereNot('status', 'completed')` selalu
benar, sehingga menghitung **seluruh** permintaan), kalimat deskripsi peran,
catatan kaki "data contoh", dan daftar modul berupa teks polos yang
di-hardcode per peran — tidak sinkron dengan sidebar maupun gate rute
("Pengadaan" tanpa tautan, "Permintaan Barang" disembunyikan dari peran yang
sebenarnya boleh mengaksesnya).

Pengguna meminta dasbor yang bersih (pedoman UI 1 Oktober 2026), menarik
tanpa menjadi gimmick, dengan modul yang berikon dan berwarna kontras.

## Keputusan

**1. Lima bagian, masing-masing dijaga gate yang sama dengan rute yang
ditautkannya** — bukan daftar peran, supaya tidak ada tautan yang berujung
403 dan tidak ada daftar kedua yang bisa melenceng dari `routes/web.php`:

| Bagian | Syarat |
|---|---|
| Akses cepat (ubin modul berikon) | gate tiap modul |
| Perlu Ditindaklanjuti | gate tiap baris |
| Kondisi Stok | `kelola-gudang` atau `akses-pengadaan`; tautan hanya bila `kelola-gudang` |
| Alur Pengadaan | `akses-pengadaan` |
| Grafik mutasi / nilai pengadaan | `kelola-gudang` / `kelola-pengadaan` atau `setujui-pengadaan` |

**2. Antrean "Perlu Ditindaklanjuti" dihitung langsung dari data, bukan dari
baris notifikasi.** Lonceng adalah kotak masuk per kejadian; dasbor adalah
beban kerja saat ini. Hanya baris yang jumlahnya lebih dari nol yang tampil.

**3. Setiap angka yang bisa diklik menautkan filter daftar yang menghasilkan
angka yang sama.** Kalau tidak ada filter yang cocok, angkanya tidak
ditampilkan atau dibentuk ulang — bukan ditautkan ke daftar yang isinya
berbeda.

**4. Baris permintaan barang (sub sistem Alma) diisi minimal**, tanpa
menyentuh berkas Alma: pemetaan peran mengikuti penerima
`NotificationScanner::periksaPermintaan()`, cakupannya mengikuti
`RequestController::index()` (permintaan umum dikecualikan, Kepala Unit hanya
unitnya, Pengguna hanya miliknya). Query metrik lama yang keliru ikut hilang.

**5. Grafik mutasi menghitung jumlah transaksi, bukan kuantitas**, per bulan
menurut `mutation_date`, enam bulan terakhir, tanpa `SaldoAwal`. Arah dibaca
dari tanda `amount` yang sudah ditetapkan `StockLedger`.

**6. Grafik nilai pengadaan memakai `amount_received × unit_price` realisasi
`Diterima`, menurut tanggal terima** — rumus yang sama dengan rekap daftar
realisasi (ADR 0017, 0023, 0026). Ditolak dan Dibatalkan tidak pernah
terhitung.

**7. Tidak ada dependensi baru.** ApexCharts sudah dibawa template TailAdmin;
ikon ditulis sebagai SVG sebaris dalam satu komponen `x-ui.icon` (gaya
Lucide, lisensi ISC). Pasangan warna grafik (brand `#465fff` + oranye
`#eb6834` terang / `#d95926` gelap) lolos uji keterbedaan buta warna dan
kontras terhadap latar kartu di kedua mode.

## Alternatif yang ditolak

**Antrean diturunkan dari baris notifikasi milik pengguna.** Menarik karena
penerima tiap keadaan sudah dipetakan pemindai. Ditolak karena: "Tandai semua
dibaca" tidak boleh mengosongkan beban kerja; sebagian keadaan baru muncul
setelah ambang hari (`UsulanMenggantung`, `RealisasiBelumDibelanjakan`,
`BastBelumDiunggah`), sehingga antrean akan terlambat berhari-hari; dan
angkanya bergantung pada pemindaian pagi sudah berjalan atau belum.

**Grafik kuantitas barang masuk/keluar.** Satuan bercampur (rim, botol,
pcs); menjumlahkan 10 rim dengan 10 botol tidak bermakna.

**Tahap akhir alur "Diterima bulan ini".** Filter tanggal daftar realisasi
menyaring `procurement_date`, bukan tanggal terima, sehingga angkanya tidak
bisa ditautkan ke daftar yang sama (melanggar butir 3). Tahap akhir menjadi
total Diterima; tampilan per bulan dijawab grafik nilai pengadaan.

**Baris "BAST belum diunggah".** Daftar realisasi tidak punya filter "tanpa
BAST", jadi angkanya tidak bisa ditautkan dengan jujur. Notifikasi
`BastBelumDiunggah` sudah menanganinya.

**Hijau/merah untuk masuk/keluar.** Keduanya warna status (aman/habis) di
sistem ini; dipakai sebagai warna seri, makna keduanya akan bertabrakan.

**Blok "Login terakhir … dari IP".** Data teknis yang tidak ditindaklanjuti
staf kantor; tetap tersedia di halaman profil.

## Konsekuensi

- `UserRole::description()` tidak lagi dipakai di mana pun. Dibiarkan karena
  enum itu milik bersama dengan sub sistem Permintaan.
- Baris permintaan memakai status mentah (`submitted`, `approved_by_head`,
  `processed_by_staff`) milik Alma. Bila ia mengganti statusnya menjadi enum,
  `DashboardController::tugasPermintaan()` ikut disesuaikan.
- Uji `RoleAccessTest` yang memastikan Pengelola Gudang tidak melihat grup
  menu Persetujuan kini mencocokkan `href="/pengadaan?status=…"` relatif khas
  sidebar: dasbor menautkan filter yang sama secara sah lewat `route()` yang
  absolut.
- Interaksi grafik (tooltip, penggantian tema) hanya diperiksa lewat
  tangkapan layar Edge headless, bukan uji otomatis.
