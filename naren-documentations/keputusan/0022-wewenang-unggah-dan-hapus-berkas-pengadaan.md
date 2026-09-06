# ADR 0022 — Wewenang mengunggah dan menghapus berkas pengadaan

## Status

Diterima — 6 September 2026

## Konteks

[ADR 0018](0018-berkas-unggahan-di-disk-privat.md) sudah menetapkan **di mana**
berkas pengadaan disimpan (disk privat), **apa** yang boleh menyusul (BAST
bertanda tangan), dan **bagaimana** ketiadaannya ditagih (notifikasi keadaan
`BastBelumDiunggah`). Yang belum dijawabnya: **siapa** yang boleh melampirkan
berkas, dan siapa yang boleh membuangnya lagi.

Pertanyaan itu baru muncul ketika Tahap 4 benar-benar ditulis, dan jawabannya
tidak sesederhana yang terlihat. [ADR 0016](0016-alur-pengadaan-tiga-dokumen.md)
memisahkan tugas dengan tegas — tidak ada satu peran pun yang dapat menjalankan
seluruh siklus sendirian — sehingga godaan pertamanya adalah meneruskan
pemisahan itu ke berkas: BAST hanya boleh diunggah Pengelola Gudang, SPK hanya
oleh Pejabat Pengadaan.

## Keputusan

### Mengunggah: satu jalur, gate `akses-pengadaan`

Satu rute, satu formulir, dan jenis dokumen adalah **isian**, bukan izin.
Kedua peran pengadaan — beserta Admin lewat `Gate::before` — boleh mengunggah
kedua jenis berkas.

Alasannya: **pemisahan tugas mengatur siapa yang memutuskan, bukan siapa yang
melampirkan bukti.** Yang dijaga ADR 0016 adalah agar mengusulkan, merealisasi,
dan mengonfirmasi penerimaan tidak jatuh ke satu tangan — dan penjagaan itu
sepenuhnya ada di rute usulan, realisasi, dan penerimaan. Sebuah berkas pindaian
tidak menggerakkan status apa pun, tidak menyentuh stok, dan tidak memutuskan
apa pun.

Siapa yang melampirkan tetap tercatat: kolom `uploaded_by` diisi dari sesi,
tidak pernah dari klien, dan setiap unggahan menulis satu baris `audit_logs`.
Yang dibutuhkan di sini adalah **atribusi**, dan atribusi sudah ada.

### Menghapus: pengunggahnya sendiri, atau Admin

Berkas boleh dihapus, tetapi hanya oleh orang yang mengunggahnya atau oleh
Admin. Penghapusan membuang berkas fisik lebih dulu, baru barisnya, dan
menulis jejak audit yang memuat jenis dan nama asli berkasnya.

Urutan itu disengaja: baris yatim tanpa berkas masih dapat ditemukan dan
diperbaiki, sedangkan berkas yatim tanpa baris tidak akan pernah terhapus oleh
siapa pun karena tidak ada lagi yang menunjuknya.

### Mengunduh: gate `akses-pengadaan`, berkas asing dianggap tidak ada

Unduhan melewati rute terautentikasi dan mengembalikan berkas dengan **nama
aslinya**. Berkas yang tidak menempel pada pengadaan di URL dijawab **404**,
bukan 403.

### `DocumentStorage` memegang seluruh konvensi

Path (`pengadaan/{procurement_id}/{uuid}.{ext}`), pengacakan nama, pemilihan
disk, penghapusan, dan pemeriksaan keberadaan berkas hanya ditulis di satu
tempat. Controller tidak pernah menyebut nama disk maupun menyusun path.

## Alternatif yang ditolak

**Membagi izin unggah menurut jenis dokumen.** Terlihat konsisten dengan
pemisahan tugas ADR 0016. Ditolak karena memisahkan hal yang salah: yang
dipisah ADR 0016 adalah wewenang memutuskan, bukan wewenang melampirkan bukti.
Di lapangan, salinan kontrak dapat berada di tangan gudang dan pindaian BAST
dapat lebih dulu sampai ke Pejabat Pengadaan; sistem yang menolak lampiran yang
sudah benar tidak membuat orang menunggu orang yang "berhak", melainkan
membuat berkas itu dikirim lewat WhatsApp dan tidak pernah masuk sistem sama
sekali.

**Berkas tidak boleh dihapus sama sekali, seperti `stock_mutations` dan
`audit_logs`.** Konsisten dengan [ADR 0005](0005-buku-mutasi-immutable-dan-urutan-pencatatan.md).
Ditolak karena alasan immutabilitas di sana tidak berlaku di sini: satu baris
mutasi adalah suku dalam penjumlahan saldo, sehingga menghapusnya merusak
angka yang sudah dipakai dokumen lain. Satu baris berkas tidak dijumlahkan ke
mana pun. Sementara itu salah unggah — memilih berkas yang keliru dari folder
pindaian — adalah kekeliruan yang biasa, dan pindaian yang salah yang
tertinggal permanen di samping yang benar justru **lebih** menyesatkan pemeriksa
daripada tidak ada berkas sama sekali. Penghapusannya sendiri tercatat di
`audit_logs`, yang memang immutable.

**Siapa pun yang boleh membaca boleh menghapus.** Ditolak: membaca dan
memusnahkan adalah dua kewenangan yang berbeda. Batas "pengunggahnya sendiri"
mempersempit penghapusan menjadi hanya memperbaiki kekeliruan sendiri, dan
Admin tetap ada sebagai jalan keluar ketika pengunggahnya sudah tidak aktif.

**Menjawab 403 untuk berkas milik pengadaan lain.** Lebih jujur secara
semantik. Ditolak karena 403 justru membenarkan bahwa berkas itu ada:
URL dapat dipakai menebak keberadaan dokumen pengadaan yang tidak boleh
dilihat. 404 tidak membocorkan apa pun.

**Menyimpan berkas dengan nama aslinya di disk.** Memudahkan penelusuran
manual di `storage/`. Ditolak karena dua unggahan bernama sama akan saling
menimpa, nama asli sering memuat spasi dan karakter non-ASCII, dan nama yang
berasal dari klien adalah permukaan serangan path traversal yang tidak perlu
dibuka. Nama aslinya tetap tersimpan di kolom `original_name` dan dikembalikan
utuh saat berkas diunduh.

## Konsekuensi

- Menambah jenis dokumen baru cukup menambah case pada
  `ProcurementDocumentType`; tidak ada rute, gate, atau migrasi yang ikut
  berubah.
- `uploaded_by` memakai `restrictOnDelete`, sehingga akun yang pernah
  mengunggah tidak dapat dihapus — sejalan dengan
  [ADR 0003](0003-arsip-alih-alih-hapus.md), akun bertransaksi diarsipkan.
- Penghapusan sebuah pengadaan kelak **wajib** memanggil `DocumentStorage`:
  `cascadeOnDelete` pada basis data tidak menyentuh berkas fisik. Saat ini
  belum ada rute hapus pengadaan, jadi belum ada yang perlu dipanggil — tetapi
  ini yang pertama harus diingat ketika rute itu dibuat.
- Uji memakai `Storage::fake()`, sehingga berkas uji tidak pernah menyentuh
  disk sungguhan.
