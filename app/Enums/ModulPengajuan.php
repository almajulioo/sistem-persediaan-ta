<?php

namespace App\Enums;

use App\Models\User;

/**
 * Dua pintu pengajuan barang bagi pegawai, dengan warna identitas dan catatan
 * perbedaannya. Satu sumber untuk judul halaman, catatan, tombol, dan dasbor,
 * supaya keduanya tidak tertukar:
 *
 * - Permintaan (toska): mengambil barang yang sudah tersedia di gudang.
 * - Permohonan (ungu): barang baru atau tambah stok, diteruskan ke pengadaan.
 */
enum ModulPengajuan: string
{
    case Permintaan = 'permintaan';
    case Permohonan = 'permohonan';

    public function label(): string
    {
        return match ($this) {
            self::Permintaan => 'Permintaan Barang',
            self::Permohonan => 'Permohonan',
        };
    }

    public function labelPendek(): string
    {
        return match ($this) {
            self::Permintaan => 'Permintaan',
            self::Permohonan => 'Permohonan',
        };
    }

    /**
     * Nama warna App\Support\Warna.
     */
    public function warna(): string
    {
        return match ($this) {
            self::Permintaan => 'teal',
            self::Permohonan => 'violet',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Permintaan => 'inbox',
            self::Permohonan => 'clipboard-list',
        };
    }

    /**
     * Tombol utama modul. Satu tingkat lebih gelap dari ubinnya supaya teks
     * putihnya tetap terbaca (kontras ≥ 4,5:1).
     */
    public function tombolClass(): string
    {
        return match ($this) {
            self::Permintaan => 'bg-teal-700 hover:bg-teal-800 text-white',
            self::Permohonan => 'bg-violet-600 hover:bg-violet-700 text-white',
        };
    }

    /**
     * Warna garis aksen di tepi atas kartu utama modul (pasangkan dengan
     * `border-t-[3px]`). Varian gelap ikut ditulis supaya tidak tertimpa
     * `dark:border-gray-800` milik kartu.
     */
    public function aksenClass(): string
    {
        return match ($this) {
            self::Permintaan => 'border-t-teal-500 dark:border-t-teal-500',
            self::Permohonan => 'border-t-violet-500 dark:border-t-violet-500',
        };
    }

    public function tautanClass(): string
    {
        return match ($this) {
            self::Permintaan => 'text-teal-700 hover:text-teal-800 dark:text-teal-400 dark:hover:text-teal-300',
            self::Permohonan => 'text-violet-600 hover:text-violet-700 dark:text-violet-400 dark:hover:text-violet-300',
        };
    }

    public function catatanClass(): string
    {
        return match ($this) {
            self::Permintaan => 'border-teal-200 bg-teal-50/60 dark:border-teal-500/30 dark:bg-teal-500/10',
            self::Permohonan => 'border-violet-200 bg-violet-50/60 dark:border-violet-500/30 dark:bg-violet-500/10',
        };
    }

    /**
     * Ringkasan satu kalimat di bawah judul halaman dan kartu dasbor.
     */
    public function ringkas(): string
    {
        return match ($this) {
            self::Permintaan => 'Ambil barang yang sudah tersedia di gudang.',
            self::Permohonan => 'Mohon barang baru atau tambah stok yang kurang.',
        };
    }

    public function dipakaiBila(): string
    {
        return match ($this) {
            self::Permintaan => 'Barang sudah terdaftar dan stoknya tersedia di gudang.',
            self::Permohonan => 'Barang belum terdaftar, atau stok di gudang tidak mencukupi.',
        };
    }

    /**
     * Apa yang terjadi setelah diajukan. Penyetuju permintaan disebut menurut
     * unit pemohon bila diketahui, sama dengan label status permintaan.
     */
    public function hasil(?User $pemohon = null): string
    {
        return match ($this) {
            self::Permintaan => 'Disetujui '.($pemohon?->unit?->sebutanKepala() ?? 'atasan').' Anda, lalu barang diserahkan Pengelola Gudang.',
            self::Permohonan => 'Ditinjau Pengelola Gudang, lalu diteruskan ke pengadaan. Barang tidak langsung diserahkan.',
        };
    }

    /**
     * Pertanyaan yang mengarahkan pengguna ke modul ini dari modul satunya.
     */
    public function ajakan(): string
    {
        return match ($this) {
            self::Permintaan => 'Barangnya sudah tersedia di gudang?',
            self::Permohonan => 'Barang belum terdaftar atau stoknya kurang?',
        };
    }

    public function labelBuat(): string
    {
        return match ($this) {
            self::Permintaan => 'Buat Permintaan',
            self::Permohonan => 'Ajukan Permohonan',
        };
    }

    public function ruteBuat(): string
    {
        return match ($this) {
            self::Permintaan => route('request.create'),
            self::Permohonan => route('item-applications.create'),
        };
    }

    public function ruteDaftar(): string
    {
        return match ($this) {
            self::Permintaan => route('request.index'),
            self::Permohonan => route('item-applications.index'),
        };
    }

    public function lawan(): self
    {
        return match ($this) {
            self::Permintaan => self::Permohonan,
            self::Permohonan => self::Permintaan,
        };
    }
}
