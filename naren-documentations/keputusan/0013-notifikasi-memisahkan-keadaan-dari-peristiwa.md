# ADR 0013 — Notifikasi memisahkan keadaan dari peristiwa

## Status

Diterima — 4 September 2026

Sebagian digantikan oleh [ADR 0036](0036-lonceng-kotak-masuk-dan-notifikasi-selesai-dihapus.md)
— 27 September 2026: keadaan yang pulih kini dihapus (kolom `resolved_at`
dibuang), dan lonceng hanya berisi notifikasi yang belum dibaca.

## Konteks

Kebutuhan yang diajukan sederhana: pengguna ingin diberi tahu ketika stok sebuah
barang habis atau menipis, supaya dapat mengajukan usulan pengadaan atau
mengarsipkan barang yang memang tidak dipakai lagi.

Yang tidak sederhana adalah **bentuk data notifikasinya**. Mekanisme notifikasi
yang lazim — termasuk tabel `notifications` bawaan Laravel — memperlakukan
notifikasi sebagai catatan yang hanya bertambah: satu baris lahir, dibaca, lalu
tinggal selamanya.

Bentuk itu keliru untuk kasus ini, dan kekeliruannya baru terlihat saat
dijalankan pada data nyata. "Stok kertas HVS habis" bukan sesuatu yang *terjadi*
pada satu titik waktu; ia sesuatu yang **sedang benar**, dan bisa berhenti benar
tanpa siapa pun menekan tombol.

Dua jalan buntu yang mengintai:

1. **Satu notifikasi per peristiwa perubahan stok.** Barang yang sudah habis
   tetap dikenai mutasi — koreksi, penerimaan sebagian, penyesuaian opname.
   Setiap mutasi melahirkan baris baru. Dengan 15 barang aktif pada data contoh
   saja, lonceng terisi puluhan baris berbunyi sama dalam hitungan hari. Fitur
   yang isinya diabaikan orang lebih buruk daripada tidak ada, karena ia
   memberi kesan sistem sudah mengawasi padahal tidak ada yang membaca.

2. **Satu notifikasi yang tidak pernah ditutup.** Barisnya tidak menumpuk,
   tetapi "stok kertas habis" tetap terpampang setelah kertasnya diisi kemarin.
   Ini lebih berbahaya daripada bising: ia **menyesatkan**, dan pada sistem yang
   dipakai untuk mempertanggungjawabkan persediaan, informasi yang salah lebih
   mahal daripada informasi yang tidak ada.

## Keputusan

Notifikasi dibagi menjadi dua sifat, dan **sifatnya melekat pada jenisnya**,
dinyatakan lewat `NotificationType::bersifatKeadaan()`.

**Keadaan** adalah sesuatu yang sedang benar: stok habis, stok menipis, stok
berlebih, barang tidak bergerak, draf opname menggantung, opname siap diposting,
periode belum diopname. Baris keadaan **menutup dirinya sendiri** dengan mengisi
`resolved_at` begitu kondisinya pulih — tanpa pengguna perlu mengklik apa pun.

**Peristiwa** adalah fakta historis: opname diposting, stok bergerak setelah
dicacah, mutasi manual sensitif dicatat. Baris peristiwa tidak pernah ditutup
sendiri; ia hilang dari lonceng setelah dibaca.

Penopangnya satu hal teknis: **indeks unik `(user_id, dedup_key)`** pada tabel
`user_notifications`. Seluruh penulisan memakai `firstOrNew` atas pasangan itu,
sehingga setiap pemeriksaan bersifat **idempoten** — dijalankan sekali,
sepuluh kali, atau seratus kali menghasilkan keadaan akhir yang sama.

Antarmukanya menyusul dari sana. `Notifier` hanya punya dua primitif:

```php
Notifier::terapkanKeadaan(berlaku: $item->stockStatus() === StockStatus::Habis, ...);
Notifier::catatPeristiwa(...);
```

`terapkanKeadaan()` menerima sebuah boolean, bukan perintah "kirim". Bila benar,
notifikasinya dipastikan ada; bila salah, notifikasinya ditutup. Pemanggil tidak
pernah perlu tahu apakah notifikasinya sudah ada sebelumnya.

Keadaan yang **kambuh** — barang yang stoknya bergoyang di sekitar batas minimum
— memakai ulang baris yang sama (`resolved_at` dikosongkan) alih-alih membuat
baris baru, sekaligus mengosongkan `read_at` supaya ia menagih lagi dan
menyetel ulang `created_at` karena yang ditampilkan adalah episode yang sedang
berlangsung.

Tabel dinamai `user_notifications`, **bukan** `notifications`. Model `User` sudah
memakai trait `Notifiable` bawaan Laravel, yang mengklaim nama itu beserta
bentuk kolomnya sendiri (`notifiable_type`, `notifiable_id`, `data`); memakai
nama tersebut untuk skema berbeda akan membuat `$user->notifications` meledak.
Nama ini juga menyisakan tabel bawaan tetap bebas seandainya notifikasi surel
kelak dibangun di atas mekanisme Laravel.

Satu baris melayani satu penerima (fan-out saat penulisan), sehingga `read_at`
bekerja per orang tanpa tabel pivot. Karena `dedup_key` mencakup `user_id`,
pemeriksaan berikutnya otomatis membuatkan baris bagi pengguna yang baru
ditambahkan.

## Alternatif yang ditolak

**Tabel dan kelas `Notification` bawaan Laravel.** Pilihan paling wajar, dan
`Notifiable` memang sudah terpasang. Ditolak karena empat hal. Kolom `data`
berupa gumpalan JSON sehingga pertanyaan "apakah keadaan ini sudah
dinotifikasikan dan belum pulih" harus dijawab lewat penggalian JSON, bukan
indeks. Tidak ada tempat bagi `resolved_at`, dan menambahkannya ke tabel
berbentuk baku itu justru membingungkan. Sepuluh jenis notifikasi berarti
sepuluh kelas `Notification` — jauh lebih banyak kode daripada satu enum. Dan
`notifiable_id` bawaan bertipe bigint sedangkan `users.user_id` adalah UUID.

**Menyimpan hanya hitungan, dihitung ulang tiap permintaan.** Tanpa tabel sama
sekali: lonceng cukup menjalankan kueri agregat. Sederhana dan selalu benar.
Ditolak karena tidak bisa menyimpan status "sudah dibaca", tidak bisa menampung
peristiwa yang memang perlu diarsipkan, dan menaruh biaya kueri pada setiap
pemuatan halaman.

**Satu baris per keadaan, ditujukan ke peran, dengan tabel pivot untuk status
baca.** Lebih normal secara basis data dan menghemat baris. Ditolak karena
menambah satu tabel dan satu join untuk menghemat baris yang, pada kantor
dengan segelintir pengguna, tidak perlu dihemat.

**Menyimpan riwayat tiap episode keadaan.** Baris lama tidak dipakai ulang saat
keadaan kambuh, sehingga dapat dijawab "berapa kali barang ini habis tahun ini".
Ditolak karena pertanyaan itu sudah dijawab `stock_mutations`, yang memang buku
resminya. Lonceng bukan tempat menyimpan riwayat.

## Konsekuensi

- Riwayat berapa kali sebuah keadaan berulang **tidak tersimpan** di notifikasi.
  Disengaja; `stock_mutations` yang memegangnya.
- Notifikasi keadaan hilang sendiri tanpa jejak tindakan. Bila kelak perlu
  dibuktikan bahwa seseorang menindaklanjuti sebuah peringatan, itu harus datang
  dari `audit_logs`, bukan dari sini.
- Menambah jenis notifikasi berarti menambah satu case enum beserta satu
  pemeriksaan di `NotificationScanner` — tidak ada kelas baru. Modul Pengadaan
  cukup menambah case dan pemeriksaannya sendiri.
- `user_notifications` **bukan tabel bersama**; seluruh isinya milik sub sistem
  ini. Bila Alma kelak ingin mengirim notifikasi dari sub sistem permintaan,
  `Notifier` sudah cukup umum untuk dipakai bersama, dan saat itulah tabelnya
  berubah status menjadi tabel bersama.
- Kolom `subject_type` menyimpan alias morph untuk model yang terdaftar di
  morph map (`opname`) tetapi nama kelas lengkap untuk yang tidak (`Item`).
  Tidak seragam, tetapi konsisten dengan dirinya sendiri karena penulisan dan
  pembacaan sama-sama memakai `getMorphClass()`. Morph map sengaja tidak
  disentuh: docblock-nya menyatakan diri khusus untuk
  `stock_mutations.reference_*` ([ADR 0007](0007-kolom-audit-dan-referensi-polimorfik.md)),
  dan menambahinya demi kerapian kolom lain memperluas kontrak yang dipakai
  bersama tanpa alasan yang sepadan.
