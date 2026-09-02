# Modul Manajemen Pengguna

CRUD akun pengguna. **Khusus Admin.** Karena sistem tertutup tanpa pendaftaran
mandiri, modul inilah satu-satunya cara akun bertambah.

## Rute

Seluruhnya di bawah `middleware('role:admin')` — modul ini memang eksklusif satu
peran, sehingga `role:` tepat dipakai di sini.

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/pengguna` | `users.index` | Daftar + cari + saring + paginasi |
| GET | `/pengguna/create` | `users.create` | Formulir tambah |
| POST | `/pengguna` | `users.store` | Simpan |
| GET | `/pengguna/{user}/edit` | `users.edit` | Formulir ubah |
| PUT | `/pengguna/{user}` | `users.update` | Perbarui |
| PATCH | `/pengguna/{user}/status` | `users.toggle-status` | Aktifkan / nonaktifkan |
| DELETE | `/pengguna/{user}` | `users.destroy` | Hapus |

## Aturan bisnis

- Admin **tidak dapat** mengubah peran, menonaktifkan, atau menghapus akunnya
  sendiri. Tombolnya disembunyikan di antarmuka **dan** ditolak di server.
- Akun yang sudah memiliki jejak audit, mutasi stok, stock opname, pengadaan,
  atau usulan pengadaan **tidak dapat dihapus**. Sistem menolak dengan menyebut
  jenis data penghalangnya dan menyarankan menonaktifkan. Lihat
  [ADR 0003](../keputusan/0003-arsip-alih-alih-hapus.md).
- Kata sandi bersifat opsional saat mengubah — kosongkan bila tidak diganti.
- Menonaktifkan akun memutus sesi aktifnya pada permintaan berikutnya.
- Setiap tambah, ubah, hapus, dan ubah status tercatat ke `audit_logs`.

## Validasi

| Field | Aturan |
|---|---|
| `username` | Wajib, 3–50 karakter, huruf/angka/titik/tanda hubung/garis bawah, unik |
| `fullname` | Wajib, maks 255 |
| `email` | Wajib, format email, unik |
| `role` | Wajib, harus salah satu `UserRole` |
| `unit` | Wajib, maks 255 |
| `password` | Wajib saat menambah, opsional saat mengubah; kebijakan kata sandi berlaku |
| `is_active` | Wajib, boolean |

Nama pengguna dinormalkan; titik diizinkan agar pola seperti `budi.santoso` dapat
dipakai — aturan `alpha_dash` bawaan Laravel menolak titik, sehingga diganti
dengan regex sendiri.

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Http/Controllers/Admin/UserController.php](../../app/Http/Controllers/Admin/UserController.php) | Seluruh aksi |
| [app/Http/Requests/Admin/](../../app/Http/Requests/Admin/) | `StoreUserRequest`, `UpdateUserRequest`, `UserFormRules` |
| [resources/views/pages/users/](../../resources/views/pages/users/) | `index`, `create`, `edit`, `_form` |
| [app/Models/User.php](../../app/Models/User.php) | `blockingRelations()`, `hasRole()`, `initials()` |

## Komponen yang lahir dari modul ini

Dipakai ulang oleh seluruh modul berikutnya:

- `x-form.text-field`, `x-form.select-field`, `x-form.password-field`
- `x-ui.flash` — banner pesan sukses dan gagal
- `resources/views/vendor/pagination/tailadmin.blade.php` — paginasi bergaya
  TailAdmin

## Catatan

Penjaga "Admin aktif terakhir" sempat ditulis lalu **dihapus** karena tidak
pernah dapat tereksekusi: pelaku aksi selalu Admin aktif lain, sedangkan
menghapus atau menonaktifkan diri sendiri sudah diblokir lebih dulu. Sistem tetap
terjamin memiliki minimal satu Admin.
