# ADR 0046 — Token kiriman untuk semua formulir pembuat dokumen

## Status

Diterima — 8 November 2026

Menggantikan sebagian [ADR 0045](0045-kiriman-ulang-formulir-usulan-dan-draf-usang.md):
`procurement_requests.submission_token` diganti tabel `form_submissions`.

## Konteks

ADR 0045 menutup kiriman ulang (tombol Kembali, klik ganda) hanya untuk usulan
pengadaan. Lima formulir lain masih terbuka: mutasi stok manual, stok opname,
usulan barang baru, realisasi pengadaan, dan unggah berkas pengadaan.

Kolom token per tabel tidak bisa diteruskan: `stock_mutations` dan
`item_proposals` adalah tabel bersama, dan baris mutasi lahir lewat
`StockLedger` lalu tidak boleh diubah.

## Keputusan

- Tabel `form_submissions` (`token` kunci primer, `subject` polimorfik,
  `created_at`) dipakai **keenam** formulir, termasuk usulan pengadaan.
  Token lama dipindahkan, kolomnya dibuang.
- `App\Support\KirimanFormulir::sekali($request, fn () => ...)` menjalankan
  pembuatan dokumen dan mencatat tokennya dalam satu transaksi, lalu
  mengembalikan `[dokumen, ulang]`. Kiriman ulang mengembalikan dokumen lama;
  kiriman bersamaan ditolak kunci primer. Token wajib; tanpa token →
  galat validasi `submission_token`.
- `x-form.token-kiriman` menggantikan input token buatan tangan dan sekaligus
  memuat ulang halaman yang dipulihkan tombol Kembali setelah terkirim.
  `x-form.sekali-kirim` tetap untuk halaman draf (tiga formulir, `draft_id`).

## Konsekuensi

- Formulir pembuat dokumen baru cukup memakai keduanya.
- Berkas yang sudah tersimpan ke disk sebelum transaksi unggah kalah dalam
  kiriman bersamaan tidak ikut terhapus — hanya pada klik ganda yang
  benar-benar bersamaan.
- Formulir sub sistem Permintaan milik Alma (permintaan, permohonan) belum
  memakai pola ini.
