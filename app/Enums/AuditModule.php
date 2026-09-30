<?php

namespace App\Enums;

/**
 * Modul asal sebuah baris jejak audit, disimpan pada `audit_logs.module`.
 *
 * Kolom ini ada semata-mata supaya log dapat disaring per modul. Kalimat pada
 * kolom `activity` tidak dapat dipakai untuk itu: menyaring "pengadaan" dengan
 * LIKE ikut menangkap usulan pengadaan dan dokumen pengadaan sekaligus, tiga
 * modul yang berbeda. Lihat ADR 0034.
 */
enum AuditModule: string
{
    case Autentikasi = 'autentikasi';
    case Pengguna = 'pengguna';
    case Profil = 'profil';
    case Barang = 'barang';
    case UsulanBarang = 'usulan_barang';
    case Mutasi = 'mutasi';
    case Opname = 'opname';
    case Penyedia = 'penyedia';
    case UsulanPengadaan = 'usulan_pengadaan';
    case Pengadaan = 'pengadaan';
    case Penerimaan = 'penerimaan';
    case DokumenPengadaan = 'dokumen_pengadaan';
    case Permintaan = 'permintaan';

    public function label(): string
    {
        return match ($this) {
            self::Autentikasi => 'Autentikasi',
            self::Pengguna => 'Manajemen Pengguna',
            self::Profil => 'Profil Pengguna',
            self::Barang => 'Master Barang',
            self::UsulanBarang => 'Usulan Barang Baru',
            self::Mutasi => 'Mutasi Stok',
            self::Opname => 'Stok Opname',
            self::Penyedia => 'Penyedia Barang',
            self::UsulanPengadaan => 'Usulan Pengadaan',
            self::Pengadaan => 'Realisasi Pengadaan',
            self::Penerimaan => 'Penerimaan Barang',
            self::DokumenPengadaan => 'Dokumen Pengadaan',
            self::Permintaan => 'Permintaan Barang',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Autentikasi => 'Masuk dan keluar sesi pengguna.',
            self::Pengguna => 'Pembuatan akun, pengubahan peran, dan penonaktifan pengguna.',
            self::Profil => 'Perubahan akun oleh pemiliknya sendiri: email, kata sandi, dan foto profil.',
            self::Barang => 'Pendaftaran dan perubahan data induk barang persediaan.',
            self::UsulanBarang => 'Usulan barang yang belum terdaftar di katalog, beserta keputusannya.',
            self::Mutasi => 'Pencatatan mutasi stok manual.',
            self::Opname => 'Pembuatan, pencacahan, dan pemostingan stok opname.',
            self::Penyedia => 'Data penyedia barang.',
            self::UsulanPengadaan => 'Usulan pengadaan dari gudang beserta keputusan pejabat pengadaan.',
            self::Pengadaan => 'Realisasi pengadaan, persetujuan, perubahan, dan pembatalannya.',
            self::Penerimaan => 'Konfirmasi penerimaan barang yang menambah stok.',
            self::DokumenPengadaan => 'Berkas pengadaan pada disk privat: unggah, unduh, dan hapus.',
            self::Permintaan => 'Permintaan barang oleh unit kerja. Disediakan untuk sub sistem Permintaan.',
        };
    }

    /**
     * Kelas badge TailAdmin untuk kolom Modul.
     *
     * Modul serumpun sengaja diberi warna yang sama supaya barisnya mudah
     * dikelompokkan mata saat menelusuri log yang panjang.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Autentikasi => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
            self::Pengguna, self::Profil => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::Barang, self::UsulanBarang, self::Mutasi, self::Opname => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Penyedia, self::UsulanPengadaan, self::Pengadaan, self::Penerimaan, self::DokumenPengadaan => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Permintaan => 'bg-info-50 text-info-600 dark:bg-info-500/15 dark:text-info-400',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown penyaring.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $module) => $carry + [$module->value => $module->label()],
            [],
        );
    }
}
