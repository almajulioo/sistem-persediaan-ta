# ADR 0014 — Pemeriksaan notifikasi dipanggil eksplisit, bukan lewat hook di StockLedger

## Status

Diterima — 4 September 2026

## Konteks

Notifikasi stok harus muncul ketika stok berubah. Pertanyaannya: dari mana
pemeriksaan itu dipicu.

Godaan pertama adalah menaruhnya di dalam `StockLedger::record()`. Di sanalah
setiap perubahan stok lewat — satu-satunya jalur sah menurut
[ADR 0004](0004-stok-hanya-lewat-stockledger.md) — sehingga tidak ada pemanggil
yang bisa lalai. Ini penting karena `StockLedger` adalah **kontrak lintas sub
sistem**: Alma memanggilnya dari modul permintaan barang saat barang diserahkan,
yaitu justru saat stok turun menuju nol. Pemanggilan eksplisit yang harus
diingat setiap pemanggil jelas rawan terlewat.

Menaruh notifikasi langsung di dalam transaksi `StockLedger` tidak bisa:
notifikasi akan tetap tertulis walaupun transaksi luar dibatalkan, dan
`OpnamePoster::post()` yang memanggil `record()` puluhan kali dalam satu
transaksi akan menyalakannya berulang-ulang di tengah jalan.

Jawaban Laravel untuk itu adalah `DB::afterCommit()`. Sebelum dipakai, mekanisme
ini diperiksa langsung ke kode kerangka kerjanya, bukan diandaikan.

Hasil pemeriksaan menutup jalan itu:

- `Illuminate\Database\DatabaseTransactionsManager::afterCommitCallbacksShouldBeExecuted()`
  mengembalikan `$level === 0`, artinya callback hanya dijalankan ketika
  transaksi terluar benar-benar commit.
- `Illuminate\Foundation\Testing\RefreshDatabase::beginDatabaseTransaction()`
  membungkus **setiap uji** dalam satu transaksi yang tidak pernah di-commit,
  melainkan di-rollback saat uji selesai.

Konsekuensinya: di bawah `RefreshDatabase`, level transaksi tidak pernah kembali
ke nol, sehingga callback `afterCommit` **tidak pernah dijalankan sama sekali di
dalam uji**. Seluruh berkas uji fitur proyek ini memakai `RefreshDatabase` lewat
`tests/Pest.php`. Memilih mekanisme itu berarti membangun fitur yang tidak dapat
diuji — bertentangan dengan aturan proyek yang mewajibkan uji tiap modul.

## Keputusan

Pemeriksaan dipanggil **eksplisit** dari pemanggil, persis seperti
`AuditLogger::record()` dipanggil: di controller, setelah transaksi selesai.

```php
AuditLogger::record($request->user(), "Mencatat mutasi manual …");

NotificationScanner::periksaBarang($item);
NotificationScanner::catatMutasiManual($mutation, $request->user());
```

Titik panggilnya empat: `StockMutationController::store()`,
`ItemController::store()`/`update()`/`toggleStatus()`, `ItemController::destroy()`
(menghapus notifikasi subjek), dan `OpnamePoster::post()` di luar transaksi,
sejajar dengan `AuditLogger`.

Yang membuat keputusan ini tidak rapuh adalah sifat idempoten yang sudah
ditetapkan [ADR 0013](0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md).
Karena pemeriksaan menyatakan *keadaan apa yang sedang benar* dan bukan
*tambahkan satu baris*, **pemeriksaan reaktif hanyalah percepatan, bukan syarat
kebenaran**. Bila sebuah jalur perubahan stok tidak memanggilnya — termasuk
pemanggilan `StockLedger` dari sub sistem lain — notifikasinya tetap muncul pada
pemeriksaan terjadwal berikutnya. Yang hilang hanya kesegeraan, bukan datanya.

Pemeriksaan terjadwal itu adalah perintah `php artisan notifikasi:periksa`,
dijadwalkan harian pukul 06.00 lewat `routes/console.php`. Ia juga menangkap
hal yang memang tidak terpicu perubahan stok sama sekali: periode opname yang
jatuh tempo, draf yang menggantung, dan barang yang tidak bergerak.

## Alternatif yang ditolak

**`DB::afterCommit()` di dalam `StockLedger::record()`.** Otomatis untuk semua
pemanggil termasuk sub sistem lain, tanpa mengubah signature. Ditolak karena
bukti di atas: callback-nya tidak pernah berjalan di bawah `RefreshDatabase`,
sehingga fiturnya tidak dapat diuji. Sekaligus ia menambah efek samping pada
kontrak lintas sub sistem yang docblock-nya sendiri menekankan alur yang
eksplisit dan terbaca.

**Model observer atau Eloquent event pada `Item`.** Idiomatik di Laravel dan
otomatis. Ditolak karena proyek ini **tidak memakai event, listener, maupun
observer di mana pun** — tidak ada `app/Events`, `app/Listeners`, maupun
`app/Observers`. Memperkenalkan satu-satunya observer di seluruh basis kode
berarti pembaca berikutnya harus tahu ada logika yang berjalan tanpa terlihat di
alur pemanggilan. Ia juga menyimpan masalah rollback yang sama.

**Hanya mengandalkan pemeriksaan terjadwal, tanpa pemicu reaktif.** Paling
sedikit kodenya dan paling sedikit titik sentuh. Ditolak karena stok habis yang
baru diketahui besok pagi menghilangkan sebagian besar gunanya — petugas yang
baru saja mencatat barang rusak seharusnya langsung melihat akibatnya.

**Pemeriksaan malas ber-throttle saat request masuk**, sebagai pengganti cron.
Menghindari kebutuhan cron di server BPK sama sekali. Ditolak karena
membebankan pekerjaan latar pada permintaan pengguna yang kebetulan apes, dan
membuat waktu pemeriksaan bergantung pada ada tidaknya orang yang membuka
aplikasi.

## Konsekuensi

- **Modul baru wajib memanggil `NotificationScanner` sendiri** bila ingin
  notifikasinya seketika. Modul Pengadaan perlu memanggilnya setelah penerimaan
  barang dikonfirmasi ([ADR 0008](0008-penerimaan-barang-oleh-pengelola-gudang.md)).
- **Sub sistem permintaan barang milik Alma belum memanggilnya.** Akibatnya
  terbatas dan sudah diketahui: penyerahan barang yang menghabiskan stok baru
  memunculkan notifikasi pada pemeriksaan pagi berikutnya. Bila kelak dianggap
  perlu seketika, cukup satu baris `NotificationScanner::periksaBarang($item)`
  setelah pemanggilan `StockLedger` miliknya — tidak ada perubahan skema maupun
  kontrak.
- Proyek ini kini punya **penjadwal pertama**. Di server dibutuhkan satu entri
  cron `php artisan schedule:run`. Bila tidak dipasang, notifikasi berbasis
  waktu tidak pernah muncul; notifikasi stok tetap berjalan lewat pemicu
  reaktif. Perintahnya juga dapat dijalankan manual kapan saja.
- Kegagalan menulis notifikasi **tidak pernah menggagalkan aksi pengguna**.
  `Notifier` menelan galatnya sendiri ke log aplikasi, meniru `AuditLogger`.
