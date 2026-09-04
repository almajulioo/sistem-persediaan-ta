# ADR 0011 — Alasan selisih: enum baku berpasangan keterangan wajib

## Status

Diterima — 4 September 2026

## Konteks

Ketika hasil cacah berbeda dari stok tercatat, petugas wajib menyatakan
penyebabnya. Pertanyaannya: disediakan daftar pilihan baku, atau kolom tulis
bebas?

Keduanya punya kelemahan yang nyata. Tulis bebas menghasilkan data yang tidak
dapat direkap — "rusak", "Rusak", "brg rusak", dan "kena air" semuanya berarti
sama tetapi terhitung empat hal berbeda. Sebaliknya daftar tertutup memaksa
kejadian yang tidak terduga masuk ke kotak yang salah, dan menghapus justru
rincian yang paling dicari pemeriksa: *barang mana, berapa, kena apa*.

Konteksnya lembaga pemeriksa keuangan. Selisih stok adalah temuan yang harus
dapat dipertanggungjawabkan satu per satu, bukan sekadar angka rekapitulasi.

## Keputusan

**Keduanya dipakai bersama, dan keduanya wajib.**

`app/Enums/AlasanSelisih.php` memuat enam alasan baku:

| Nilai | Label | Makna |
|---|---|---|
| `barang_rusak` | Barang Rusak | Barang ada tetapi tidak layak pakai sehingga tidak dihitung |
| `barang_hilang` | Barang Hilang | Barang tidak ditemukan di gudang |
| `kadaluarsa` | Kadaluarsa | Barang melewati masa pakai |
| `kesalahan_pencatatan` | Kesalahan Pencatatan | Jumlah pada transaksi sebelumnya tercatat keliru |
| `transaksi_belum_dibukukan` | Transaksi Belum Dibukukan | Penerimaan atau penyerahan sudah terjadi tetapi belum dicatat |
| `lainnya` | Lainnya | Sebab lain yang dijelaskan pada keterangan |

Kolom `adjustment_note` menampung penjelasan bebas, **maksimal 500 karakter**.

Validasi bersifat bersyarat: bila `stock_real` terisi **dan** berbeda dari
`stock_stored`, maka `adjustment_reason` dan `adjustment_note` menjadi wajib.
Nilai `stock_stored` dibaca dari basis data, bukan dari request, supaya tidak
dapat dipalsukan lewat formulir.

Aturan yang sama ditegakkan **dua kali**: di `UpdateOpnameCountRequest` sebagai
validasi formulir, dan di `OpnamePoster::pastikanSiap()` sebagai penjaga domain.
Lapisan kedua diperlukan karena posting tidak selalu datang dari formulir yang
sama — data contoh dan uji memanggil `OpnamePoster` langsung.

Keterangan mutasi dirakit dari keduanya, `"{label} — {keterangan}"`, sehingga
baris di riwayat mutasi dapat dibaca tanpa membuka dokumen opname-nya.

## Alternatif yang ditolak

**Hanya enum.** Rapi untuk direkap, tetapi menghapus rincian yang justru dicari
pemeriksa. "Barang Rusak" tidak menjawab pertanyaan berapa dan mengapa. Case
`Lainnya` pun menjadi tidak berguna tanpa kolom penjelas.

**Hanya tulis bebas.** Tidak dapat direkap, tidak dapat disaring, tidak dapat
diberi warna badge. Sepuluh opname kemudian, tidak ada yang dapat menjawab
"berapa banyak selisih tahun ini yang disebabkan barang hilang?".

**Memakai ulang `MutationType` sebagai alasan selisih.** `MutationType` sudah
merangkap alasan mutasi manual
([ADR 0006](0006-mutationtype-merangkap-alasan-manual.md)), sehingga tampak
hemat. Ditolak karena dua daftar ini menjawab pertanyaan yang berbeda:
`MutationType` menyatakan *jenis pergerakan stok*, `AlasanSelisih` menyatakan
*mengapa buku dan rak tidak cocok*. `KesalahanPencatatan` dan
`TransaksiBelumDibukukan` tidak masuk akal sebagai jenis mutasi manual, dan
seluruh mutasi hasil opname tetap berjenis `Penyesuaian` apa pun alasannya.
Menggabungkan keduanya akan mengaburkan arti kolom `mutation_type`.

**Keterangan opsional untuk alasan yang sudah jelas.** Misalnya "Barang Hilang"
dianggap cukup menjelaskan diri sendiri. Ditolak karena justru alasan itulah yang
paling perlu diuraikan pada pemeriksaan.

## Konsekuensi

- Petugas mengisi dua kolom untuk setiap selisih, bukan satu. Beban ini
  disengaja dan hanya muncul pada baris yang berselisih; baris yang cocok tidak
  meminta apa pun.
- Selisih dapat direkap menurut alasan, disaring, dan diberi warna: merah untuk
  kehilangan fisik (rusak, hilang, kadaluarsa), kuning untuk persoalan
  pencatatan, abu-abu untuk lainnya.
- Menambah alasan baru berarti menambah case enum — perubahan kode, bukan data.
  Dapat diterima karena daftarnya berubah sangat jarang, dan enum memberi
  keamanan tipe di seluruh sistem.
- Data lama tidak terdampak: `adjustment_note` sudah ada sejak ERD awal,
  sedangkan `adjustment_reason` ditambahkan dan boleh kosong untuk baris tanpa
  selisih.
