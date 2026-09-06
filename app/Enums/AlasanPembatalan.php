<?php

namespace App\Enums;

/**
 * Alasan baku dibatalkannya sebuah realisasi pengadaan, disimpan pada
 * `procurements.cancel_reason`.
 *
 * Selalu dipasangkan dengan keterangan bebas yang wajib diisi: enum membuat
 * pembatalan dapat direkap, keterangan menangkap kekhususan tiap kasus —
 * pola yang sama dengan AlasanSelisih pada stok opname (ADR 0011).
 *
 * Pembelian barangnya terjadi di luar sistem, di antara realisasi dan
 * penerimaan (ADR 0016). Justru karena itu kegagalan di ruang luar itu harus
 * punya cara dinyatakan di dalam sistem. Lihat ADR 0024.
 */
enum AlasanPembatalan: string
{
    case PenyediaGagalKirim = 'penyedia_gagal_kirim';
    case PenyediaMembatalkan = 'penyedia_membatalkan';
    case KebutuhanBerubah = 'kebutuhan_berubah';
    case KesalahanPencatatan = 'kesalahan_pencatatan';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::PenyediaGagalKirim => 'Penyedia Gagal Kirim',
            self::PenyediaMembatalkan => 'Penyedia Membatalkan',
            self::KebutuhanBerubah => 'Kebutuhan Berubah',
            self::KesalahanPencatatan => 'Kesalahan Pencatatan',
            self::Lainnya => 'Lainnya',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PenyediaGagalKirim => 'Penyedia tidak sanggup memenuhi pesanan sampai batas waktu yang disepakati.',
            self::PenyediaMembatalkan => 'Penyedia menarik diri dari pesanan yang sudah disepakati.',
            self::KebutuhanBerubah => 'Barang tidak lagi diperlukan sehingga pengadaannya tidak dilanjutkan.',
            self::KesalahanPencatatan => 'Realisasi dibuat keliru, misalnya salah penyedia, salah harga, atau salah jumlah.',
            self::Lainnya => 'Sebab lain yang dijelaskan pada keterangan.',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $reason) => $carry + [$reason->value => $reason->label()],
            [],
        );
    }
}
