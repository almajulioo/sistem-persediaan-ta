<?php

namespace App\Enums;

/**
 * Jenis mutasi stok, disimpan pada kolom `stock_mutations.mutation_type`.
 *
 * Enum ini merangkap dua peran: klasifikasi buku besar stok, sekaligus alasan
 * baku untuk mutasi manual. Dengan begitu kolom "Jenis Mutasi" pada layar
 * riwayat langsung bermakna tanpa perlu kolom alasan terpisah.
 *
 * Arah mutasi ditentukan oleh jenisnya, bukan oleh tanda nilai yang dikirim
 * pemanggil. Lihat App\Support\StockLedger.
 */
enum MutationType: string
{
    // --- Berasal dari dokumen atau proses sistem ---
    case SaldoAwal = 'saldo_awal';
    case Masuk = 'masuk';
    case Keluar = 'keluar';
    case Penyesuaian = 'penyesuaian';

    // --- Dicatat manual oleh Pengelola Gudang ---
    case HibahMasuk = 'hibah_masuk';
    case ReturDariUnit = 'retur_dari_unit';
    case BarangRusak = 'barang_rusak';
    case BarangHilang = 'barang_hilang';
    case Kadaluarsa = 'kadaluarsa';
    case KoreksiPencatatan = 'koreksi_pencatatan';

    public function label(): string
    {
        return match ($this) {
            self::SaldoAwal => 'Saldo Awal',
            self::Masuk => 'Barang Masuk',
            self::Keluar => 'Barang Keluar',
            self::Penyesuaian => 'Penyesuaian Opname',
            self::HibahMasuk => 'Hibah Masuk',
            self::ReturDariUnit => 'Retur dari Unit',
            self::BarangRusak => 'Barang Rusak',
            self::BarangHilang => 'Barang Hilang',
            self::Kadaluarsa => 'Kadaluarsa',
            self::KoreksiPencatatan => 'Koreksi Pencatatan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SaldoAwal => 'Stok pembuka saat barang pertama kali didaftarkan.',
            self::Masuk => 'Penerimaan barang dari pengadaan.',
            self::Keluar => 'Penyerahan barang atas permintaan unit kerja.',
            self::Penyesuaian => 'Koreksi stok hasil stock opname.',
            self::HibahMasuk => 'Barang diterima sebagai hibah, di luar pengadaan.',
            self::ReturDariUnit => 'Barang dikembalikan unit kerja ke gudang.',
            self::BarangRusak => 'Barang rusak dan tidak layak pakai.',
            self::BarangHilang => 'Barang tidak ditemukan di gudang.',
            self::Kadaluarsa => 'Barang melewati masa pakai.',
            self::KoreksiPencatatan => 'Perbaikan atas kesalahan pencatatan sebelumnya.',
        };
    }

    /**
     * Apakah jenis ini boleh dicatat lewat formulir mutasi manual.
     *
     * Jenis yang berasal dari dokumen sengaja tidak boleh, supaya tidak ada
     * mutasi yang seolah-olah lahir dari pengadaan atau permintaan.
     */
    public function isManual(): bool
    {
        return match ($this) {
            self::SaldoAwal, self::Masuk, self::Keluar, self::Penyesuaian => false,
            default => true,
        };
    }

    /**
     * Menambah stok (+1), mengurangi stok (-1), atau mengikuti tanda nilai yang
     * diberikan (0) untuk jenis yang bisa naik maupun turun.
     */
    public function direction(): int
    {
        return match ($this) {
            self::SaldoAwal, self::Masuk, self::HibahMasuk, self::ReturDariUnit => 1,
            self::Keluar, self::BarangRusak, self::BarangHilang, self::Kadaluarsa => -1,
            self::Penyesuaian, self::KoreksiPencatatan => 0,
        };
    }

    /**
     * Apakah arah mutasi ditentukan pengguna, bukan oleh jenisnya.
     */
    public function isBidirectional(): bool
    {
        return $this->direction() === 0;
    }

    /**
     * Kelas badge TailAdmin untuk menampilkan jenis mutasi di UI.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::SaldoAwal => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::Masuk, self::HibahMasuk, self::ReturDariUnit => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Keluar, self::BarangRusak, self::BarangHilang, self::Kadaluarsa => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Penyesuaian, self::KoreksiPencatatan => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
        };
    }

    /**
     * @return array<string, string> nilai => label, seluruh jenis (untuk penyaring).
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $type) => $carry + [$type->value => $type->label()],
            [],
        );
    }

    /**
     * @return array<string, string> nilai => label, hanya jenis yang boleh
     *                               dipilih pada formulir mutasi manual.
     */
    public static function manualOptions(): array
    {
        return array_reduce(
            array_filter(self::cases(), fn (self $type) => $type->isManual()),
            fn (array $carry, self $type) => $carry + [$type->value => $type->label()],
            [],
        );
    }
}
