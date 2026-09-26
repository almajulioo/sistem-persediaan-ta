# ADR 0037 — Lonceng: kuota per bagian, pengingat ulang keadaan, dan peristiwa berulang

## Status

Diterima — 27 September 2026

Menggantikan sebagian [ADR 0036](0036-lonceng-kotak-masuk-dan-notifikasi-selesai-dihapus.md):
keadaan yang sudah dibaca tidak lagi diam selamanya sampai kondisinya pulih,
melainkan dikembalikan ke lonceng menurut tingkatnya. Selebihnya ADR 0036 tetap
berlaku — lonceng tetap kotak masuk, dan yang selesai tetap dihapus.

## Konteks

ADR 0036 menutup sesi paginya dengan tiga hal yang sengaja dibiarkan terbuka:

1. **"Aktivitas Terbaru" dapat kosong.** Lonceng mengambil sepuluh baris yang
   belum dibaca, diurutkan menurut tingkat, *baru kemudian* membaginya menjadi
   dua bagian. Sepuluh keadaan berlevel Perlu Perhatian cukup untuk menyisakan
   nol tempat bagi peristiwa — dan peristiwa berlevel Informasi selalu kalah
   urutan dari keadaan mana pun.
2. **Keadaan yang sudah dibaca tidak pernah menagih lagi.** ADR 0036 menerima
   ini sebagai harga dari lonceng yang dapat dibersihkan: "stok habis" yang
   dibaca hari Senin diam sampai stoknya pulih. Lebih parah untuk antrean
   persetujuan: `RealisasiMenungguPersetujuan` tidak punya keadaan lanjutan
   seperti `UsulanMenggantung`, sehingga Kasubbag yang sekali membacanya lalu
   lupa tidak pernah diingatkan siapa pun.
3. **Peristiwa yang terulang tidak terdengar.** Baris peristiwa dipakai ulang
   per `dedup_key` tanpa menyetel ulang `read_at` maupun `created_at`.
   Realisasi yang ditolak, diperbaiki, lalu ditolak lagi hanya mengganti teks
   baris lama yang sudah dibaca — Pejabat Pengadaan tidak diberi tahu, dan
   baris itu terhapus tujuh hari setelah penolakan *pertama*. Kasus nyatanya
   dua, keduanya dari alur ADR 0030: ditolak lagi, atau disetujui lagi setelah
   diubah. Peristiwa lain hanya terjadi sekali per dokumen.

Rencana diajukan sebelum membangun, lengkap dengan tiga pertanyaan keputusan.
Pengguna menyetujui seluruh rekomendasinya.

## Keputusan

**1. Kuota per bagian.** Lonceng mengambil kedua bagiannya dengan kueri
terpisah, masing-masing lima baris, lewat scope baru `Notification::keadaan()`
dan `peristiwa()`.

- *Perlu Tindakan* tetap diurutkan menurut tingkat lalu kebaruan — ia antrean
  pekerjaan.
- *Aktivitas Terbaru* diurutkan menurut **kebaruan saja** (`terbaru()`) — ia
  linimasa, dan kabar terbaru tidak boleh tenggelam di bawah kabar lama yang
  tingkatnya lebih tinggi.
- Keduanya memakai UUID sebagai penentu seri: `HasUuids` membangkitkannya
  berurut waktu, sehingga baris yang lahir pada detik yang sama tetap punya
  urutan pasti.
- Tiap bagian menampilkan jumlahnya, dan **"Lihat N lainnya"** bila jumlahnya
  melebihi lima, menuju `/notifikasi?status=belum_dibaca&sifat=…`. Halaman
  notifikasi mendapat penyaring baru **Sifat** (Perlu tindakan / Aktivitas)
  supaya tautan itu mendarat pada daftar yang sama.
- Angka badge dijumlahkan dari kedua bagian, bukan dihitung terpisah, supaya
  tidak pernah menjanjikan sesuatu yang tidak ditampilkan lonceng.

Biayanya tiga kueri per halaman (dua daftar dan satu hitungan per jenis),
naik dari dua.

**2. Pengingat ulang menurut tingkat.** Pemeriksaan terjadwal
(`notifikasi:periksa`) mengembalikan keadaan yang masih berlangsung tetapi sudah
lama dibaca menjadi belum dibaca, lewat `Notifier::ingatkanUlang()`:

| Tingkat | Diingatkan ulang | `NotificationLevel::hariIngatkanUlang()` |
|---|---|---|
| Mendesak | tiap pagi | 1 |
| Perlu Perhatian | 7 hari setelah dibaca | 7 |
| Informasi | tidak pernah | `null` |

Angkanya diletakkan pada enum tingkat karena memang turunan dari
`description()`-nya: yang mendesak "tindak hari ini juga", yang perlu perhatian
"menjadi masalah bila diabaikan", informasi "tanpa tenggat". Ambang dihitung
**per hari kalender**, bukan per 24 jam, supaya pemeriksaan pagi menagih semua
yang dibaca kemarin — dan supaya menjalankan perintahnya dua kali sehari tidak
menagih dua kali. Dipanggil sesudah pemeriksaan, sehingga keadaan yang baru
saja pulih sudah terhapus dan tidak ikut ditagih. Peristiwa tidak pernah
diingatkan ulang.

**3. Setiap pencatatan peristiwa adalah kejadian baru.** `catatPeristiwa()`
kini menyetel ulang `read_at` dan `created_at` bila barisnya sudah ada, sehingga
kabar yang terulang kembali ke lonceng dan umur tujuh harinya dihitung dari
kejadian terakhir. `terapkanKeadaan()` **tidak** ikut, dan itu inti
pembedanya: pemeriksaan terjadwal menulis ulang setiap keadaan tiap pagi, dan
itu bukan kejadian baru. Aman karena peristiwa hanya dicatat dari tindakan
pengguna, tidak pernah dari pemeriksaan terjadwal.

## Alternatif yang ditolak

**Satu kueri dengan kuota lebih besar, lalu dibagi.** Menaikkan batas dari
sepuluh ke, misalnya, dua puluh hanya menggeser titik kelaparannya, dan tetap
tidak dapat menyebut berapa yang tidak termuat.

**Pengingat ulang harian untuk seluruh keadaan.** "Tandai semua dibaca"
berubah menjadi pekerjaan harian, dan keadaan berlevel Informasi — barang yang
tidak bergerak 180 hari, stok berlebih — tidak punya tenggat yang pantas
ditagih setiap pagi.

**Pengingat ulang untuk Mendesak saja.** Paling sederhana, tetapi membiarkan
antrean persetujuan realisasi tanpa penagih sama sekali.

**Keadaan mendesak dipaku di lonceng, tidak dapat disingkirkan.** Bertentangan
dengan keputusan pertama ADR 0036: lonceng harus dapat dibersihkan.

**Menyetel ulang `created_at` saat mengingatkan ulang.** Waktu yang tampil pada
keadaan menyatakan sejak kapan keadaan itu berlangsung — "3 hari yang lalu"
pada Stok Habis justru informasinya. Hanya `read_at` yang dikosongkan.

**Satu baris per kejadian peristiwa** — `dedup_key` memuat waktu keputusan,
sehingga penolakan pertama dan kedua sama-sama terlihat di halaman notifikasi.
Ditolak: riwayat lengkapnya sudah ada di `audit_logs`, kuncinya butuh data
khusus per jenis, dan lonceng akan memuat dua kabar tentang dokumen yang sama.

## Konsekuensi

- Untuk pertama kalinya **pemeriksaan terjadwal mengubah status baca**. Tanpa
  cron tidak ada pengingat ulang; yang lain tetap berjalan lewat pemicu
  reaktif.
- Admin menerima seluruh keadaan gudang, jadi setiap pagi seluruh Stok Habis
  yang belum pulih juga kembali ke lonceng Admin.
- Teks peristiwa yang terulang menimpa teks sebelumnya — alasan penolakan
  pertama hanya tersisa di `audit_logs`.
- Uji pengingat ulang memakai waktu tepat di batas hari (`startOfDay()`,
  `endOfDay()`). Waktu relatif seperti `now()->subDay()` jatuh pada detik yang
  sama dengan ambangnya — kolom waktu hanya menyimpan sampai detik — sehingga
  uji gagal atau lolos karena alasan yang salah. Dibuktikan dengan memasang
  sementara ambang 24 jam dan ambang yang meleset sehari; keduanya tertangkap.
