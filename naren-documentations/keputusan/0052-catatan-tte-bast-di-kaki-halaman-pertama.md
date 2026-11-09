# ADR 0052 — Catatan TTE BAST di kaki halaman 1, tanpa waktu di bawah QR

## Status

Diterima — 9 November 2026

Menggantikan sebagian:

- [ADR 0042](0042-bast-pengadaan-format-resmi-bpk.md): halaman 1 BAST yang
  ber-TTE kini punya kaki halaman. BAST kosong dan BAST lama tetap tanpa kaki
  halaman.
- [ADR 0051](0051-bast-pengadaan-digital-dengan-tte-qr.md), bagian 7 "PDF":
  QR tidak lagi diikuti "Ditandatangani secara elektronik" dan waktunya, dan
  catatan TTE tidak lagi berada tepat di bawah tabel tanda tangan.

## Konteks

Sejak ADR 0051, kolom tanda tangan yang sudah ber-TTE berisi QR, lalu dua
baris kecil di bawahnya: "Ditandatangani secara elektronik" dan waktu TTE
("9 Nov 2026 10:15 WIB"). Di bawah tabel tanda tangan ada satu catatan
miring: "Dokumen ini ditandatangani secara elektronik. Keaslian tanda tangan
dapat diperiksa dengan memindai kode QR."

Narendra meminta dua hal pada PDF BAST:

1. tulisan kecil berisi waktu di bawah QR dihapus;
2. catatan TTE dipindah ke kaki halaman, paling bawah, dan tidak muncul di
   lampiran.

## Keputusan

**1. Tulisan di bawah QR dihapus seluruhnya**, kedua barisnya. Kalimat
"Ditandatangani secara elektronik" tidak dipertahankan sendirian, karena
catatan di kaki halaman sudah menyatakan hal yang sama untuk seluruh dokumen.
Waktu TTE tetap tercatat di `procurement_receipt_signatures.signed_at` dan
tampil di halaman verifikasi yang dibuka QR. Kunci `waktu` dibuang dari
`BastPengadaan::untukCetak()` karena tidak lagi dipakai.

**2. Catatan TTE menjadi kaki halaman 1 saja.** Elemennya tetap di posisi yang
sama dalam alur dokumen (sebelum lampiran), tetapi diberi
`position: absolute; bottom: 0`. Di dompdf, elemen `absolute` ditempatkan pada
halaman tempat ia muncul dalam alur, sehingga catatan menempel ke dasar area
isi halaman 1. Catatan tetap hanya tercetak bila ada TTE (`adaTte`), sehingga
BAST kosong tidak berubah.

## Alternatif yang ditolak

- **`position: fixed`**, cara kaki halaman laporan lain (`pdf._style`).
  Elemen `fixed` diulang dompdf di setiap halaman, lampiran ikut.
- **`page_script` dompdf dengan syarat `$PAGE_NUM == 1`.** Bisa, tetapi
  teksnya digambar di kanvas dari controller, di luar template. Gaya dan
  isinya jadi terpisah dari view yang memuat seluruh BAST.
- **Hanya menghapus baris waktunya** dan menyisakan "Ditandatangani secara
  elektronik" di bawah QR. Ditolak karena mengulang kalimat yang kini ada di
  kaki halaman.

## Konsekuensi

- Waktu TTE tidak terbaca dari kertasnya. Siapa pun yang perlu tahu kapan
  sebuah pihak bertanda tangan memindai QR-nya.
- Isi yang dibekukan dan sidiknya (`content`/`content_hash`) tidak berubah:
  waktu TTE tidak pernah menjadi bagian isi BAST, dan kode verifikasi tetap
  sama. BAST yang sudah ber-TTE langsung tercetak dengan tata letak baru.
- Halaman 1 tidak memuat apa pun di antara tabel tanda tangan dan kaki
  halaman. Bila kelak isi halaman 1 memanjang sampai ke dasar halaman,
  catatan bisa menimpa isi. Saat ini halaman 1 terisi sekitar dua pertiga.
