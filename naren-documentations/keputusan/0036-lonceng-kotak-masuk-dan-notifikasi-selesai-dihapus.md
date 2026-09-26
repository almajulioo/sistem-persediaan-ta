# ADR 0036 — Lonceng sebagai kotak masuk; notifikasi yang selesai dihapus

## Status

Diterima — 27 September 2026

Menggantikan sebagian [ADR 0013](0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md):
cara keadaan ditutup (`resolved_at`) dan kapan sebuah notifikasi hilang dari
lonceng. Pemisahan keadaan dari peristiwa, idempotensi lewat indeks unik
`(user_id, dedup_key)`, dan kedua primitif `Notifier` tetap berlaku.

Sebagian digantikan oleh [ADR 0037](0037-kuota-lonceng-pengingat-ulang-dan-peristiwa-berulang.md)
— 27 September 2026: keadaan yang sudah dibaca kini diingatkan ulang menurut
tingkatnya, bukan diam sampai pulih.

## Konteks

Sebelum mengubah perilaku notifikasi, pengguna meminta penjelasan
mekanismenya. Penelusuran kode menemukan empat hal yang tidak cocok dengan
rancangan ADR 0013 maupun dokumentasinya:

1. **Peristiwa tidak pernah hilang dari lonceng.** ADR 0013 dan
   `modul/notifikasi.md` menyatakan peristiwa "hilang dari lonceng setelah
   dibaca", tetapi `NotificationDropdown` hanya menyaring `resolved_at IS NULL`
   — dan `resolved_at` peristiwa memang selamanya null. Membaca hanya
   menghapus huruf tebal. Bagian "Aktivitas Terbaru" praktis berisi lima
   peristiwa terpenting sepanjang masa. Tidak ada uji yang menjaga isi lonceng,
   jadi selisih ini lolos sejak modul dibangun.
2. **Peristiwa tidak pernah dibersihkan.** `Notifier::bersihkan()` mensyaratkan
   `resolved_at` terisi, sehingga baris peristiwa menumpuk selamanya.
3. **Keadaan yang pulih menumpuk.** Barisnya hanya ditandai `resolved_at`,
   tetap tampil di halaman notifikasi dengan penanda "Sudah pulih", dan
   penyaring status "Sudah selesai" ada semata untuk menampilkannya.
4. **Badge dan isi lonceng bisa tidak cocok.** Badge menghitung seluruh yang
   belum dibaca, termasuk keadaan yang sudah pulih tetapi tidak lagi
   ditampilkan.

Pengguna lalu meminta empat perubahan: "Tandai semua dibaca" membuat lonceng
benar-benar bersih sementara notifikasinya tetap dapat dilihat di halaman
notifikasi; lonceng tidak menampilkan kalimat lengkap; baris yang selesai
langsung dihapus dari basis data sehingga status "Sudah selesai" tidak
diperlukan; dan peristiwa dapat selesai — saat diklik di lonceng, lewat "Tandai
semua dibaca", lewat "Tindak Lanjuti" di halaman notifikasi, dan sendiri
setelah dibiarkan tujuh hari.

**Bentrokan di dalam permintaan itu**, ditanyakan sebelum membangun: bila
peristiwa "selesai" saat ditandai dibaca, dan yang selesai langsung dihapus,
maka setelah "Tandai semua dibaca" peristiwa tidak dapat dilihat lagi di
halaman notifikasi — padahal justru itu yang diminta pada poin pertama.
Pengguna memilih: **peristiwa yang dibaca disimpan sampai berumur tujuh hari**.

## Keputusan

**1. Lonceng adalah kotak masuk.** Isinya hanya notifikasi yang **belum
dibaca**. Membaca — mengklik satu baris, atau "Tandai semua dibaca" —
menyingkirkannya dari lonceng, **termasuk keadaan yang kondisinya masih
berlangsung**. Halaman `/notifikasi` tetap menampilkan semuanya. Satu aturan
untuk kedua sifat notifikasi, dan badge kini selalu cocok dengan isi lonceng.

**2. Lonceng hanya menampilkan judul**, jenis, dan waktu. Kalimat lengkapnya
hanya ada di halaman notifikasi.

**3. Keadaan yang pulih dihapus barisnya.** `Notifier::selesaikan()` kini
menghapus, bukan mengisi `resolved_at`. Bila keadaannya kambuh, ia lahir
sebagai baris baru yang belum dibaca — hasil yang sama dengan logika "kambuh"
lama yang memakai ulang baris, dengan kode yang lebih sedikit. Kolom
`resolved_at` dibuang karena tidak ada lagi baris yang hidup dalam keadaan
"selesai". Penyaring status di halaman notifikasi menjadi *Belum dibaca* /
*Sudah dibaca*, dan kartu rekap *Total Aktif* menjadi *Total*.

**4. Peristiwa menyingkir dari lonceng saat dibaca, dan dihapus setelah tujuh
hari.** "Selesai" bagi peristiwa berarti sudah dibaca: lewat klik di lonceng,
"Tandai semua dibaca", atau "Tindak Lanjuti" di halaman notifikasi — ketiganya
memang sudah bermuara di `read_at`. Setiap peristiwa, dibaca atau belum,
dihapus tujuh hari setelah terbit oleh `Notifier::bersihkan()`, yang
dijalankan `notifikasi:periksa --bersihkan` setiap pagi. Jenis peristiwa
diambil dari `NotificationType::peristiwa()`, sehingga peristiwa baru otomatis
ikut dibersihkan.

Migrasi `2026_09_27_000001_drop_resolved_at_from_user_notifications_table`
**lebih dulu menghapus baris yang sudah selesai**, baru membuang kolomnya.
Tanpa langkah itu, keadaan yang dulu sudah pulih akan tampil kembali sebagai
notifikasi yang masih berlaku begitu kolom penandanya hilang. Indeks
`(user_id, resolved_at, read_at)` diganti `(user_id, read_at)`, sesuai
pertanyaan yang kini diajukan lonceng.

## Alternatif yang ditolak

**Peristiwa dihapus seketika saat dibaca.** Pembacaan paling harfiah atas
permintaan "selesai → hapus". Ditolak pengguna: setelah "Tandai semua dibaca",
peristiwa lenyap dari halaman notifikasi juga, padahal poin pertama justru
meminta ia tetap dapat dilihat di sana.

**Mempertahankan `resolved_at` sebagai kolom yang selalu null.** Lebih sedikit
perubahan — tujuh berkas uji Pengadaan tidak perlu disentuh. Ditolak karena
kolom yang tidak pernah terisi menyesatkan siapa pun yang membaca skema, dan
`scopeAktif()` akan menjadi penyaring yang tidak menyaring apa-apa.

**Penanda terpisah untuk "disingkirkan dari lonceng" (`dismissed_at`).** Akan
memungkinkan keadaan tetap di lonceng sampai pulih ketika diklik satu per satu,
tetapi hilang lewat "Tandai semua dibaca". Ditolak: satu kolom dan satu status
tambahan demi perbedaan yang sulit dijelaskan kepada pengguna. "Lonceng berisi
yang belum dibaca" adalah satu aturan yang dipahami siapa pun yang pernah
memakai kotak masuk.

**Menyaring peristiwa berumur lebih dari tujuh hari saat kueri, bukan hanya
saat pembersihan.** Akan membuat batas tujuh hari tepat bahkan tanpa cron.
Ditolak demi konsistensi dengan
[ADR 0014](0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md): seluruh hal
yang berbasis waktu menumpang pada pemeriksaan terjadwal. Tanpa cron, peristiwa
lama hanya bertahan lebih lama sampai perintahnya dijalankan — tidak ada yang
menjadi salah.

## Konsekuensi

- **Keadaan yang sudah dibaca tidak lagi menagih lewat lonceng**, meski
  kondisinya masih berlangsung. "Stok habis" yang dibaca hari Senin diam di
  lonceng sampai stoknya pulih lalu habis lagi. Ia tetap ada di halaman
  notifikasi dan tetap dihitung kartu *Mendesak*/*Perlu Perhatian*. Ini
  pergeseran nyata dari ADR 0013, dan diterima sebagai harga dari lonceng yang
  dapat dibersihkan.
- Riwayat **kapan** sebuah keadaan pulih tidak lagi tersimpan — dulu tampil
  sebagai "pulih … lalu". Sejalan dengan ADR 0013: riwayat resmi ada di
  `stock_mutations` dan `audit_logs`, bukan di notifikasi.
- Peristiwa yang tidak pernah dibuka terhapus setelah tujuh hari tanpa pernah
  dibaca. Dokumen yang dikabarkannya tetap dapat dibuka dari modulnya sendiri.
- **Notifikasi sub sistem Permintaan ikut aturan ini**, karena keenam jenisnya
  berupa peristiwa. Yang paling terasa: "Permintaan menunggu persetujuan" bagi
  Kepala Unit hilang dari lonceng setelah dibaca dan terhapus setelah tujuh
  hari, meski permintaannya belum diputuskan. Tidak ada berkas Alma yang
  disentuh — penulisannya lewat `NotificationScanner` — tetapi perilaku yang
  dirasakan penggunanya berubah, jadi Alma perlu diberi tahu. Jalan keluarnya
  sudah terlihat: jenis yang menuntut tindakan semestinya menjadi keadaan,
  seperti `UsulanMenungguPersetujuan` di sisi Pengadaan — keputusan untuk sesi
  terpisah, bersama Alma.
- Dalam uji, **adanya baris kini berarti keadaannya masih berlaku**. Pemanggilan
  `->aktif()` pada tujuh berkas uji Pengadaan dihapus tanpa mengubah maknanya.
- **Belum ditangani di sini**, dicatat supaya tidak hilang: peristiwa yang
  terulang pada subjek yang sama dalam tujuh hari masih memakai baris yang
  sama tanpa menyetel ulang `read_at` dan `created_at`, sehingga pengulangannya
  tidak terdengar; dan lonceng masih mengambil 10 baris sebelum membaginya ke
  dua bagian, sehingga "Aktivitas Terbaru" dapat kosong bila keadaan
  menghabiskan kuotanya.
