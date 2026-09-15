# ADR 0033 — `item_proposals` resmi jadi tabel bersama dengan sub sistem Permintaan

## Status

Diterima — 16 September 2026

## Konteks

[ADR 0032](0032-usulan-barang-baru.md) sengaja merancang `item_proposals` netral
peran, tetapi memutuskan **tidak** membangun pintu depannya untuk sub sistem
Permintaan — itu wilayah Alma, dan menyentuhnya tanpa kesepakatan melanggar
aturan tabel bersama. ADR itu mencatatnya sebagai "dikabari, bukan diasumsikan".

Ternyata Kasubbag Umum dan TI menyampaikan permintaan yang sama (poin ketiga di
sesi presentasi yang sama) ke Alma secara terpisah, dan ia membangun jawabannya
sendiri secara paralel — tanpa tahu tabel ini sudah ada. Ini baru ketahuan saat
`git pull` dari repositorinya (`feat: add item proposal`, commit `cec19d9`,
15 September 23:47) dan riwayatnya bercabang dari commit yang sama dengan sesi
ADR 0031/0032 (`e887695`).

### Tabrakan yang ditemukan

| | Punya sesi ini (ADR 0032) | Punya Alma |
|---|---|---|
| Nama tabel | `item_proposals` (migrasi `2026_09_16_...`) | `item_proposals` (migrasi `2026_09_15_...`, **nama tabel sama**, berkas beda) |
| Pengusul | `proposed_by`, `restrictOnDelete()` | `user_id`, `cascadeOnDelete()` |
| Keterangan | `purpose_note`, **wajib** | `reason`, opsional |
| Status | `diajukan` / `disetujui` / `ditolak` | `diajukan` / `ditindaklanjuti` / `ditolak` |
| Tautan ke barang jadi | `item_id` — menyetujui **memanggil** `items.store()` | tidak ada — "ditindaklanjuti" hanya mengubah status, barangnya tidak pernah benar-benar didaftarkan |

Git menandai `app/Models/ItemProposal.php` sebagai konflik nyata (`both added`).
Yang **tidak** ditandai git, tetapi sama fatalnya: `routes/web.php` berisi dua
`use ...\ItemProposalController` (folder `Gudang` dan `Request`) dengan nama
pendek yang sama — auto-merge tekstual menggabung keduanya tanpa keributan,
padahal PHP akan berhenti dengan *fatal error* "already in use" begitu berkas
ini dieksekusi. Dua migrasi yang membuat tabel bernama sama juga akan gagal pada
`migrate` kedua meski nama berkasnya berbeda dan git tidak melihatnya sebagai
konflik.

## Keputusan

**Satukan ke satu tabel, pakai skema ADR 0032.** Skema itu yang dipertahankan
karena satu-satunya yang menutup lingkaran sampai ke barang benar-benar
terdaftar; skema Alma berhenti di ubah status saja, sehingga usulan yang
"ditindaklanjuti" belum tentu benar-benar berujung barang di master.

### Yang dihapus

- Migrasi `2026_09_15_000003_create_item_proposals_table.php` milik Alma —
  tabelnya tidak pernah dijalankan di lingkungan mana pun sehingga aman dihapus
  utuh, bukan disusul migrasi susulan.
- Method `ItemProposalController::decide()` (`Request\`) dan rute
  `request.item-proposals.decide` miliknya. Digantikan dua jalur yang **sudah
  ada** dari ADR 0032, dipakai bersama oleh kedua pintu:
  - **Tolak** → `item-proposals.reject` (gate `kelola-gudang`), keterangan
    wajib.
  - **Setuju** → `items.create?item_proposal_id=…`, karena persetujuan tanpa
    baris `items` tidak berarti apa pun (alasan yang sama persis dengan ADR
    0032).

  Sengaja tidak dibuatkan jalur keputusan kedua di sisi Permintaan: usulan dari
  pintu mana pun harus diputuskan lewat satu jalur yang sama, atau cepat atau
  lambat kedua jalur itu berselisih perilaku — persis kekhawatiran yang sudah
  ditulis ADR 0032 soal "jalur pembuatan barang kedua".

### Yang diadaptasi di sisi Permintaan (dengan hati-hati, bukan ditulis ulang)

- `Request\ItemProposalController@index`/`store` — nama kolom mengikuti skema
  bersama (`proposed_by`, `purpose_note`); `store()` juga memanggil
  `AuditLogger::record()` dan `NotificationScanner::periksaUsulanBarang()`
  supaya usulan dari pintu ini ikut menyalakan notifikasi ke pengawas gudang,
  sama seperti dari pintu Pengadaan.
- `Request\StoreItemProposalRequest` — `reason` (opsional) menjadi
  `purpose_note` (wajib, `max:500`, menyamai aturan sisi Gudang), karena
  keterangan itulah bahan Pengelola Gudang menilai apakah barangnya benar-benar
  baru.
- `resources/views/pages/request/item-proposals/{create,index}.blade.php` —
  label field, badge status (`$proposal->status->badgeClass()`/`label()` lewat
  enum, bukan larik manual), dan kolom aksi: baris yang masih `Diajukan` dan
  dilihat pengelola gudang/admin menuju `route('item-proposals.show', …)` —
  halaman keputusan tunggal — alih-alih tombol "Selesaikan"/"Tolak" sendiri.
- `routes/web.php` — `use App\Http\Controllers\Request\ItemProposalController
  as RequestItemProposalController;` untuk menghindar dari tabrakan nama;
  otorisasi tiga rute Permintaan (`index`/`create`/`store`) tetap terbuka bagi
  siapa pun yang login, ditegakkan di form request — bukan gate rute — karena
  itulah yang membedakan pintu ini dari pintu Pengadaan yang membatasi
  `kelola-pengadaan`.

**Tidak ada satu pun logika di `app/Http/Controllers/Gudang/`,
`app/Http/Requests/Gudang/`, `app/Models/ItemProposal.php`, atau
`app/Enums/ItemProposalStatus.php` yang diubah.** Adaptasi seluruhnya berada di
berkas yang tadinya milik Alma, dan terbatas pada penyesuaian nama kolom serta
pemindahan jalur keputusan ke rute yang sudah ada — bukan penulisan ulang alur
kerjanya.

## Alternatif yang ditolak

**Ganti nama tabel/model Alma agar berdiri sendiri** (mis. jadi
`request_item_proposals`), pertahankan alurnya apa adanya. Risikonya paling
kecil dan tidak menyentuh logikanya sama sekali. Ditolak karena mempertahankan
persis dua kelemahan yang coba dicegah ADR 0032: dua tempat memutuskan hal yang
sama (berpotensi berselisih), dan usulan "ditindaklanjuti" versi Alma yang tidak
pernah benar-benar mendaftarkan barangnya — celah yang sama yang membuat
ADR 0032 menolak opsi "kolom `verified_at`". Menyimpan celah itu demi
menghindari risiko merge bukan pertukaran yang sepadan.

**Ganti nama tabel/model milik sesi ini**, pertahankan skema Alma. Ditolak atas
dasar biaya: pekerjaan ADR 0031/0032 sudah lengkap — 45 berkas, 47 uji, dua ADR
resmi — sedangkan sisi Alma baru delapan berkas dan belum diuji sama sekali.
Memaksakan migrasi nama pada sisi yang sudah matang untuk sisi yang baru
dirintis adalah arah yang salah.

## Konsekuensi

**Butir terbuka #4 di CLAUDE.md tertutup.** `item_proposals` sekarang benar
tabel bersama, bukan lagi "ditawarkan, belum dipakai" — perubahan skemanya ke
depan butuh kesepakatan Alma, sama seperti `users`, `items`, `stock_mutations`,
`audit_logs`.

**Alma perlu dikabari** bahwa alur "Usulan Tambah Barang" miliknya berubah:
tombol "Selesaikan" miliknya sudah tidak ada, digantikan alur "Tinjau &
Putuskan" yang mengarah ke halaman usulan barang milik Pengadaan/Gudang. Ini
perubahan perilaku pada fitur yang ia bangun sendiri, dilakukan untuk
menyelesaikan merge — bukan sekadar preferensi gaya, tetapi tetap layak
dikonfirmasi dengannya pada kesempatan berikutnya, termasuk apakah pintu
Permintaan sebaiknya justru menampilkan status "Disetujui/Ditolak" dengan
istilah yang lebih akrab bagi penggunanya.

**Belum ada uji otomatis permanen untuk pintu Permintaan.** Verifikasi ujung ke
ujung (pengguna mengusulkan → gudang menolak lewat rute bersama → gudang
menyetujui lewat `items.store` dan `item_id` tertaut) dilakukan lewat berkas uji
sementara yang dijalankan lalu dihapus, sejalan dengan batas kerja "hanya bagian
Pengadaan dan Manajemen Gudang" — menambah uji permanen di `tests/Feature/Request/`
adalah keputusan Alma, bukan yang dibuat di sini. Empat skenario yang terbukti
lolos: mengusulkan lewat pintu Permintaan tersimpan ke tabel bersama; halaman
indeksnya menampilkan usulan sendiri dan tautan tinjau bagi Gudang; penolakan
lewat rute bersama mengubah status dan `decided_by`; persetujuan lewat
`items.store` menautkan `item_id` dan mengubah status jadi `Disetujui`.

**Gotcha baru untuk merge lintas pengembang:** auto-merge tekstual git tidak
memahami semantik PHP. Dua `use` statement yang mengarah ke kelas berbeda tetapi
punya nama pendek sama akan digabung tanpa penanda konflik, dan kesalahannya
baru muncul sebagai *fatal error* saat berkasnya dieksekusi — bukan saat
`git merge`. Migrasi dua sisi yang membuat tabel bernama sama juga lolos tanpa
tanda konflik karena nama berkasnya berbeda. Keduanya perlu diperiksa manual
setiap kali menggabungkan riwayat dari kolaborator lain, terutama saat area
kerjanya pernah didiskusikan sebagai "mungkin akan dipakai bersama" — persis
peringatan yang sudah ditulis ADR 0032.

**Verifikasi:** `migrate:fresh --seed` bersih di MySQL dengan satu tabel
`item_proposals`; `composer install` menambahkan `endroid/qr-code` milik fitur
lain Alma (di luar cakupan keputusan ini, tidak disentuh); `php artisan test`
tetap 410 lolos; `npm run build` bersih; Pint bersih pada seluruh berkas yang
disentuh sesi ini.
