<?php

namespace App\Enums;

/**
 * Alasan baku terjadinya selisih antara stok tercatat dan hasil cacah fisik,
 * disimpan pada `opname_details.adjustment_reason`.
 *
 * Selalu dipasangkan dengan keterangan bebas yang wajib diisi: enum membuat
 * selisih dapat direkap, keterangan menangkap kekhususan tiap kasus.
 */
enum AlasanSelisih: string
{
    case BarangRusak = 'barang_rusak';
    case BarangHilang = 'barang_hilang';
    case Kadaluarsa = 'kadaluarsa';
    case KesalahanPencatatan = 'kesalahan_pencatatan';
    case TransaksiBelumDibukukan = 'transaksi_belum_dibukukan';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::BarangRusak => 'Barang Rusak',
            self::BarangHilang => 'Barang Hilang',
            self::Kadaluarsa => 'Kadaluarsa',
            self::KesalahanPencatatan => 'Kesalahan Pencatatan',
            self::TransaksiBelumDibukukan => 'Transaksi Belum Dibukukan',
            self::Lainnya => 'Lainnya',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BarangRusak => 'Barang ada tetapi tidak layak pakai sehingga tidak dihitung.',
            self::BarangHilang => 'Barang tidak ditemukan di gudang.',
            self::Kadaluarsa => 'Barang melewati masa pakai.',
            self::KesalahanPencatatan => 'Jumlah pada transaksi sebelumnya tercatat keliru.',
            self::TransaksiBelumDibukukan => 'Penerimaan atau penyerahan sudah terjadi tetapi belum dicatat.',
            self::Lainnya => 'Sebab lain yang dijelaskan pada keterangan.',
        };
    }

    /**
     * Kelas badge TailAdmin untuk menampilkan alasan di UI.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::BarangRusak, self::BarangHilang, self::Kadaluarsa => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::KesalahanPencatatan, self::TransaksiBelumDibukukan => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Lainnya => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown form.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $alasan) => $carry + [$alasan->value => $alasan->label()],
            [],
        );
    }
}
