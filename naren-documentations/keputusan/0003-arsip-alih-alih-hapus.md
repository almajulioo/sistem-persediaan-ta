# ADR 0003 — Akun dan barang diarsipkan, bukan dihapus

## Status

Diterima — 2 September 2026

## Konteks

Seluruh foreign key ke `users` dan `items` dari tabel transaksi memakai
`restrictOnDelete`:

| Tabel | Menunjuk | Perilaku hapus |
|---|---|---|
| `audit_logs`, `stock_mutations`, `stock_opnames`, `procurements`, `procurement_requests` | `users` | RESTRICT |
| `stock_mutations`, `opname_details`, `procurement_details`, `request_details` | `items` | RESTRICT |
| `requests` | `users` | CASCADE |

Artinya akun atau barang yang sudah bertransaksi **tidak bisa dihapus** di
tingkat basis data. Tanpa penanganan, pengguna hanya akan menerima galat SQL yang
tidak dapat dipahami.

Tabel `items` pada ERD juga tidak memiliki penanda aktif, sehingga barang yang
sudah tidak dipakai akan menumpuk selamanya di daftar dan di pilihan transaksi.

## Keputusan

1. Tambahkan kolom `is_active` pada `items`, menyusul `users` yang sudah
   memilikinya sejak ERD. Ini **improvisasi dari ERD** yang disetujui pengguna.
2. Sediakan `blockingRelations()` pada `User` dan `Item` yang memeriksa relasi
   ber-RESTRICT sebelum penghapusan dicoba.
3. Bila masih ada data terkait, penghapusan ditolak dengan pesan yang menyebut
   **jenis data penghalangnya** dan menyarankan mengarsipkan.
4. Data yang diarsipkan tidak muncul pada transaksi baru, tetapi seluruh
   riwayatnya tetap utuh.

## Alternatif yang ditolak

**Soft delete Laravel.** Menyulitkan indeks unik `item_code` dan `username` —
kode barang yang sudah dihapus lunak tetap menempati nilainya, sehingga perlu
indeks unik gabungan dengan `deleted_at`. Kolom `is_active` juga lebih jujur
menggambarkan maksudnya: barang tidak dihapus, hanya tidak dipakai lagi.

**Mengubah foreign key menjadi `cascadeOnDelete`.** Menghapus akun akan ikut
menghapus jejak auditnya — persis yang tidak boleh terjadi pada sistem di lembaga
audit.

**Membiarkan galat SQL muncul.** Pesannya tidak terbaca pengguna dan tidak
memberi tahu jalan keluarnya.

## Konsekuensi

- Setiap daftar dan pilihan transaksi harus menyaring `is_active`.
- Penghapusan tetap dimungkinkan untuk data yang benar-benar belum dipakai,
  misalnya barang yang baru saja salah didaftarkan.
- `User::RESTRICTED_RELATIONS` harus ditambah setiap kali ada tabel baru yang
  menunjuk `users` dengan RESTRICT — sudah terjadi sekali saat `stock_mutations`
  memperoleh kolom `user_id`.
