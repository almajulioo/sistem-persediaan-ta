<?php

namespace App\Enums;

/**
 * Status realisasi pengadaan, disimpan pada `procurements.status`.
 *
 * Realisasi lahir sebagai `MenungguPersetujuan`: sejak ADR 0030, dokumen yang
 * dibuat Pejabat Pengadaan harus dinilai Kasubbag Umum dan TI lebih dulu.
 * Dokumennya memang sudah tersimpan — penyedia, barang, dan harganya justru
 * yang dinilai — tetapi belum berlaku sampai disetujui.
 *
 * `MenungguPenerimaan` sengaja memakai istilah yang sama persis dengan
 * ADR 0008: realisasi berhenti di situ sampai Pengelola Gudang mengonfirmasi
 * barang fisik diterima, dan baru saat itu stok bertambah.
 *
 * `Ditolak` bukan keadaan terminal: dokumennya boleh diperbaiki, dan
 * penyimpanan perubahan itulah pengajuan ulangnya. Kalau memang tidak jadi,
 * jalan keluarnya tetap `Dibatalkan`. Keduanya sama-sama "dokumen yang tidak
 * pernah menjadi transaksi", sehingga tidak ikut dihitung pada agregat
 * (ADR 0026).
 *
 * `Diterima` adalah satu-satunya keadaan yang benar-benar final — begitu barang
 * diterima, stoknya sudah bertambah lewat mutasi yang immutable dan tidak ada
 * lagi yang boleh diubah maupun dibatalkan. Lihat ADR 0024 dan ADR 0030.
 */
enum ProcurementStatus: string
{
    case MenungguPersetujuan = 'menunggu_persetujuan';
    case MenungguPenerimaan = 'menunggu_penerimaan';
    case Diterima = 'diterima';
    case Ditolak = 'ditolak';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::MenungguPersetujuan => 'Menunggu Persetujuan',
            self::MenungguPenerimaan => 'Menunggu Penerimaan',
            self::Diterima => 'Diterima',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MenungguPersetujuan => 'Menunggu penilaian Kasubbag Umum dan TI; barang belum boleh dipesan.',
            self::MenungguPenerimaan => 'Realisasi berjalan, barang belum dikonfirmasi diterima gudang.',
            self::Diterima => 'Barang telah dikonfirmasi diterima Pengelola Gudang; stok sudah bertambah.',
            self::Ditolak => 'Ditolak Kasubbag Umum dan TI; masih dapat diperbaiki lalu diajukan ulang.',
            self::Dibatalkan => 'Realisasi tidak dilanjutkan; alasan dan keterangannya tercatat pada dokumen.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::MenungguPersetujuan => 'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-400',
            self::MenungguPenerimaan => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Diterima => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Ditolak => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Dibatalkan => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
        };
    }

    /**
     * Keadaan yang dokumennya masih boleh diubah maupun dibatalkan.
     *
     * Satu predikat untuk dua kebutuhan karena himpunannya memang sama persis:
     * selama barang belum dikonfirmasi diterima, belum ada apa pun yang terjadi
     * secara fisik — tidak ada mutasi, tidak ada BAST, stok belum bertambah.
     * Lihat ADR 0025 dan ADR 0030.
     */
    public function masihBerjalan(): bool
    {
        return match ($this) {
            self::MenungguPersetujuan, self::Ditolak, self::MenungguPenerimaan => true,
            default => false,
        };
    }

    /**
     * Keadaan yang dokumennya tidak pernah menjadi transaksi.
     *
     * Dipakai agregat rekap dan laporan: baris seperti ini tetap ditampilkan,
     * tetapi tidak pernah ikut dihitung. Lihat ADR 0026.
     *
     * @return array<int, self>
     */
    public static function takPernahJadiTransaksi(): array
    {
        return [self::Ditolak, self::Dibatalkan];
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown penyaring.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $status) => $carry + [$status->value => $status->label()],
            [],
        );
    }
}
