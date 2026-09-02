# ADR 0005 — Buku mutasi immutable dan diurutkan menurut waktu pencatatan

## Status

Diterima — 3 September 2026

## Konteks

`stock_mutations` berfungsi sebagai buku besar stok. Dua pertanyaan muncul saat
membangun layar riwayat:

1. Bolehkah baris mutasi disunting atau dihapus bila petugas salah catat?
2. Kolom `stock_after` menyimpan saldo berjalan — dalam urutan apa ia benar?

Pertanyaan kedua ditemukan saat verifikasi data: formulir mengizinkan tanggal
mutasi mundur (sampai hari ini), sehingga **urutan tanggal transaksi tidak selalu
sama dengan urutan pencatatan**. Bila daftar diurutkan menurut `mutation_date`,
kolom saldo berjalan tampak melompat-lompat dan terlihat seperti salah hitung.

## Keputusan

1. **Baris mutasi tidak pernah diubah atau dihapus.** Tidak ada rute `edit`,
   `update`, maupun `destroy` untuk mutasi. Kesalahan pencatatan dikoreksi dengan
   mutasi lawan berjenis `KoreksiPencatatan`.
2. **Buku mutasi diurutkan menurut `created_at` (waktu pencatatan)**, bukan
   `mutation_date`. `stock_after` adalah saldo *pada saat mutasi dicatat*,
   sehingga hanya koheren bila dibaca dalam urutan yang sama.
3. `mutation_date` tetap ditampilkan sebagai kolom tersendiri, dengan keterangan
   bahwa tanggal transaksi dapat berbeda dari waktu pencatatan.

Inilah cara buku besar bekerja pada umumnya: entri berurutan menurut waktu
posting, dengan tanggal transaksi sebagai atribut terpisah.

## Alternatif yang ditolak

**Mengizinkan penyuntingan mutasi.** Menghapus makna jejak audit. Bila baris
dapat diubah, tidak ada lagi bukti apa yang sebenarnya terjadi.

**Menghitung ulang `stock_after` setiap ada mutasi bertanggal mundur.** Berarti
menulis ulang riwayat: nilai yang pernah tercetak di laporan lama akan berubah.
Selain mahal, ini bertentangan dengan sifat catatan audit.

**Melarang tanggal mundur sama sekali.** Tidak realistis — pencatatan sering
terlambat dari kejadian sebenarnya.

## Konsekuensi

- Petugas tidak dapat memperbaiki salah ketik secara langsung; harus mencatat
  koreksi. Ini disengaja, dan jumlah koreksi justru menjadi indikator kualitas
  pencatatan.
- Urutan tampilan dapat terasa berbeda dari harapan bila banyak tanggal mundur.
  Diberi penjelasan lewat *tooltip* pada judul kolom.
- Migrasi yang menambahkan `stock_after` harus mengisi ulang baris lama dengan
  saldo berjalan, kalau tidak baris lama menampilkan 0.
