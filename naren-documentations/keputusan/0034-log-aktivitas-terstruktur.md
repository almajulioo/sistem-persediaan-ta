# ADR 0034 — Log aktivitas: kolom terstruktur mendampingi kalimat audit

## Status

Diterima — 16 September 2026

## Konteks

BPK menuntut akuntabilitas: tiap perubahan data penting harus dapat ditelusuri
siapa pelakunya, kapan, dari mana, dan terhadap dokumen apa. Sistem ini sudah
menulis jejaknya sejak awal — tabel `audit_logs`, model `AuditLog`, dan
`App\Support\AuditLogger` dengan 34 titik panggil tersebar di Auth, Admin,
Gudang, dan Pengadaan — tetapi **tidak pernah ada layar untuk membacanya**.
Menu "Log Aktivitas" sudah berdiri di sidebar sejak lama dengan `'path' => '#'`,
dan kartu modulnya sudah ada di dasbor tanpa kunci `route`. Jejaknya ditulis,
tidak pernah dibaca.

Persoalan sesungguhnya baru muncul saat layarnya hendak dibuat. Kolom
`activity` berisi kalimat bebas berbahasa Indonesia:

```
Menyetujui realisasi pengadaan: Realisasi #A1B2C3D4
Membatalkan Realisasi #F7E1A945 dengan alasan Penyedia Wanprestasi
Menghapus BAST Bertanda Tangan (bast-051.pdf) pada Realisasi #B723995B
```

Kalimat itu bagus untuk dibaca dan memuat hal yang tidak mungkin ditampung
kolom mana pun — kode barang, jumlah, alasan pembatalan, nomor dokumen, nama
berkas. Tetapi ia **tidak dapat disaring dengan andal**. Penyaring "seluruh
aktivitas modul Pengadaan" akan jadi `activity LIKE '%pengadaan%'`, yang ikut
menangkap *usulan* pengadaan dan *dokumen* pengadaan — tiga modul berbeda dalam
satu hasil. Begitu pula "tampilkan semua penolakan": `Menolak` muncul pada
usulan pengadaan, realisasi pengadaan, dan usulan barang baru sekaligus.

Pemeriksa yang tidak dapat mempersempit tiga ratus ribu baris menjadi "apa yang
dilakukan Pengelola Gudang pada modul opname selama triwulan lalu" sama saja
tidak punya jejak audit — ia punya tumpukan kalimat.

Tiga aksi juga ternyata belum tercatat sama sekali: pengisian hasil cacah
opname (`StockOpnameController::update` — angka yang jadi dasar penyesuaian
stok), perubahan draf usulan (`ProcurementDraftController::update`), dan
unduhan berkas pengadaan (`ProcurementDocumentController::download`).

## Keputusan

**Tambahkan kolom terstruktur di samping kalimatnya, bukan menggantikannya.**

> Kolom terstruktur untuk menyaring, kalimat untuk dibaca. Enum tidak akan
> pernah memuat kode barang atau alasan pembatalan; kalimatnya memuat itu.
> Keduanya saling melengkapi, bukan duplikasi.

Enam kolom baru pada `audit_logs`, seluruhnya nullable dan aditif:

| Kolom | Isi |
|---|---|
| `module` | Enum `AuditModule` — 12 nilai, termasuk `permintaan` yang disediakan untuk sub sistem Alma |
| `event` | Enum `AuditEvent` — 14 nilai |
| `actor_role` | **Cuplikan** `UserRole` pelaku pada saat aksi dilakukan |
| `ip_address` | Alamat asal, null untuk aksi dari konsol |
| `subject_type` / `subject_id` | Dokumen yang dikenai aksi, polimorfik lewat morph map |

Tanda tangan `AuditLogger::record()` berubah, dan seluruh 34 titik panggil
dikonversi ke *named arguments* menyamai `StockLedger::record()`:

```php
AuditLogger::record(
    user: $request->user(),
    activity: "Menambah barang: {$item->item_code} - {$item->item_name}",
    module: AuditModule::Barang,
    event: AuditEvent::Tambah,
    subject: $item,
);
```

`module` dan `event` **wajib, tanpa nilai bawaan**. Nilai bawaan akan menjamin
terjadinya pembusukan data diam-diam — persis hal yang kolom ini ada untuk
mencegahnya. `actor_role`, `ip_address`, dan pasangan `subject_*` diisi sendiri
oleh logger, tidak dititipkan ke pemanggil.

Layarnya `/log-aktivitas`, khusus Admin di bawah `middleware('role:admin')`,
dengan tujuh penyaring dan ekspor CSV. **Tidak ada rute tulis apa pun**: baris
audit bersifat *append-only*, sejalan dengan buku mutasi pada ADR 0005.

### `actor_role` adalah cuplikan, bukan turunan relasi

Ini butir yang paling mudah dianggap mubazir — perannya toh bisa dibaca dari
relasi `user`. Tidak bisa. Relasi memberi peran seseorang **sekarang**,
sedangkan jejak audit harus menyatakan kapasitas apa yang ia pakai **waktu
itu**. Begitu Pengelola Gudang dipromosikan jadi Admin, seluruh riwayat lamanya
akan ikut terbaca sebagai tindakan Admin — pemalsuan riwayat yang terjadi tanpa
seorang pun menyentuh satu baris pun. Kolomnya murah, kekeliruannya tidak.

### Hubungan dengan ADR 0007

[ADR 0007](0007-kolom-audit-dan-referensi-polimorfik.md) menolak menautkan
baris audit ke baris **mutasi** tertentu, dengan alasan tautan seperti itu
rapuh. Penolakan itu tetap berlaku dan tidak dicabut: `subject` pada mutasi
manual menunjuk **barang**-nya, bukan baris mutasinya, karena mutasi memang
tidak punya layar sendiri sedangkan kartu persediaan barang punya. Yang
ditambahkan di sini adalah tautan ke **dokumen** — realisasi, usulan, opname,
penyedia, berkas — yang seluruhnya punya halaman sendiri dan identitas yang
stabil.

Tautannya sengaja bergantung pada relasi `subject` yang sudah ter-*resolve*,
bukan pada `subject_id` mentah: baris beraksi `hapus` menunjuk dokumen yang
sudah tiada, dan menautkannya hanya akan mengantar pemeriksa ke halaman 404.

### Pencegahan injeksi rumus pada CSV

Sebagian kalimat log memuat nama berkas unggahan — satu-satunya teks di sistem
ini yang asalnya dari luar dan memang tidak dipercaya, alasan yang sama yang
membuat `DocumentStorage` menyimpannya dengan nama UUID
([ADR 0022](0022-wewenang-unggah-dan-hapus-berkas-pengadaan.md)). Nama berkas
seperti `=HYPERLINK("http://...")` akan **dijalankan Excel** begitu Admin
membuka hasil ekspornya. Sel yang diawali `=`, `+`, `-`, `@`, tab, atau CR
karena itu dibubuhi kutip tunggal sebelum ditulis.

## Alternatif yang ditolak

**Menyaring kalimat `activity` dengan `LIKE` saja, tanpa mengubah skema.** Nol
risiko terhadap tabel bersama, dan itulah daya tariknya. Ditolak karena
penyaring per-modul dan per-aksi tidak mungkin dibuat andal di atasnya: satu
kata "pengadaan" merentang tiga modul, satu kata "Menolak" merentang tiga jenis
dokumen. Penyaring yang hasilnya tidak dapat dipercaya lebih buruk daripada
tidak ada penyaring, karena pemeriksa menyangka sudah melihat semuanya.

**Tabel log kedua milik sendiri, supaya `audit_logs` yang berstatus tabel
bersama tidak perlu disentuh.** Menghindari seluruh urusan kesepakatan dengan
Alma. Ditolak karena memecah jejak audit jadi dua tempat — hal yang justru
paling tidak boleh terjadi pada jejak audit. Pemeriksa yang harus menggabungkan
dua sumber untuk tahu apa yang terjadi tidak punya jejak audit yang utuh.
Lagi pula perubahan ini seluruhnya aditif dan nullable, dan sub sistem
Permintaan tidak pernah menulis ke tabel ini sama sekali (nol panggilan
`AuditLogger` di `RequestController`), sehingga risiko merusaknya nihil.

**Menampilkan peran lewat relasi `user`, tanpa kolom `actor_role`.** Ditolak
dengan alasan yang sudah diuraikan di atas: salah secara akuntabilitas begitu
peran seseorang berubah, dan salahnya tidak kelihatan.

**Mencatat percobaan masuk yang gagal.** Sempat dirancang dan ditawarkan —
login gagal lazimnya peristiwa keamanan yang paling layak ditelusuri di sebuah
institusi. **Ditolak pengguna.** Konsekuensi baiknya nyata: `user_id` tetap
`NOT NULL`, sehingga migrasinya murni aditif dan tidak menyentuh kunci asing
`restrictOnDelete` sama sekali, `AuditLogger::record()` tetap menuntut `User`
yang sungguhan, dan `LoginRequest` tidak perlu diubah sedikit pun. Bila kelak
diperlukan, yang dibutuhkan hanyalah membuat `user_id` nullable dan menambah
satu case enum.

**Mencatat ekspor dan cetak laporan.** "Siapa mengekspor data apa" adalah
syarat audit yang klasik. Ditolak pengguna supaya log tidak tenggelam oleh
baris bervolume tinggi bernilai rendah. Unduhan **berkas pengadaan** tetap
dicatat — itu berkas bukti pada disk privat, bukan laporan yang isinya sudah
terlihat di layar.

**Ekspor `.xlsx` sungguhan lewat PhpSpreadsheet.** Ditolak: CSV ber-BOM UTF-8
sudah terbuka rapi di Excel lokal, dan menambah dependensi berat bertentangan
dengan [ADR 0009](0009-laporan-pdf-dan-csv.md) yang sengaja menghindari paket
ekspor. PDF berkop juga tidak dibuat — pengguna hanya meminta CSV.

## Konsekuensi

**`audit_logs` berubah skemanya, dan Alma perlu dikabari.** Perubahannya
aditif dan nullable sehingga tidak mungkin merusak kodenya, tetapi aturan tabel
bersama tetap berlaku. `AuditModule::Permintaan` sudah disediakan untuk dipakai
sub sistemnya, yang sampai sekarang belum menulis satu baris pun ke
`audit_logs` — seluruh alur `RequestController` masih tidak meninggalkan jejak
apa pun. Itu wilayah keputusannya, bukan keputusan yang dibuat di sini.

**Tanda tangan `AuditLogger::record()` tidak lagi kompatibel ke belakang.**
Pemanggil baru wajib menyertakan `module` dan `event`; lupa menyertakannya
berhenti sebagai `TypeError` saat pengembangan, bukan sebagai baris log cacat
yang baru ketahuan berbulan-bulan kemudian. Satu berkas di wilayah Alma,
`Request\ItemProposalController`, ikut disesuaikan — dua argumen tambahan pada
panggilan yang sudah diadaptasi di
[ADR 0033](0033-usulan-barang-baru-tabel-bersama-dengan-permintaan.md), bukan
perubahan alur.

**Usulan barang dari dua pintu tetap sulit dibedakan.** Kalimat dari
`Gudang\ItemProposalController::store` dan `Request\ItemProposalController::store`
memang identik, dan `module` keduanya sama-sama `usulan_barang` — sengaja,
supaya usulan dari pintu mana pun berkumpul di satu penyaring, sejalan dengan
alasan ADR 0033 menolak jalur keputusan kedua. Yang membedakan keduanya
sekarang adalah `actor_role`: pengusul berperan `pengguna` pasti datang dari
pintu Permintaan.

**`AuditLogSeeder` diperlukan.** Seeder lain menulis model secara langsung
tanpa melewati controller, sehingga tidak satu pun menghasilkan baris
`audit_logs`. Tanpa seeder ini layarnya kosong sesudah `migrate:fresh --seed`
dan tidak satu penyaring pun dapat diperagakan saat sidang.

**Gotcha baru:** `app()->runningInConsole()` juga bernilai true saat rangkaian
uji dijalankan lewat `php artisan test`, padahal uji fitur mensimulasikan
permintaan HTTP yang sungguhan. Pengisian `ip_address` karena itu harus
mengecualikan `runningUnitTests()`, atau kolomnya tidak akan pernah terisi di
lingkungan uji dan perilakunya tidak dapat diuji sama sekali.

**Verifikasi:** `migrate` bersih di MySQL dengan `user_id` tetap `NOT NULL` dan
FK `ON DELETE RESTRICT` utuh; `migrate:fresh --seed` menghasilkan 267 baris log
contoh menyebar di sepuluh modul; `php artisan test` 436 lolos (410 lama tetap
lolos, 26 baru); `npm run build` bersih; Pint bersih pada 26 dari 27 berkas
yang disentuh — `app/Helpers/MenuHelper.php` sudah gagal Pint sebelum sesi ini
dan sengaja tidak dirapikan, karena perubahannya di sini hanya satu string dan
merapikannya akan jadi penyapuan berkas yang tidak berkaitan.
