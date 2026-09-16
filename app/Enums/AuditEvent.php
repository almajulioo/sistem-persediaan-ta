<?php

namespace App\Enums;

/**
 * Jenis aksi sebuah baris jejak audit, disimpan pada `audit_logs.event`.
 *
 * Berpasangan dengan `audit_logs.module`: modul menyatakan *di mana*, event
 * menyatakan *apa*. Rinciannya — kode barang, jumlah, alasan pembatalan —
 * tetap tinggal di kalimat `activity`, karena enum tidak akan pernah mampu
 * memuatnya. Lihat ADR 0034.
 */
enum AuditEvent: string
{
    case Masuk = 'masuk';
    case Keluar = 'keluar';
    case Tambah = 'tambah';
    case Ubah = 'ubah';
    case UbahStatus = 'ubah_status';
    case Hapus = 'hapus';
    case Ajukan = 'ajukan';
    case Setuju = 'setuju';
    case Tolak = 'tolak';
    case Batal = 'batal';
    case Posting = 'posting';
    case Terima = 'terima';
    case Unggah = 'unggah';
    case Unduh = 'unduh';

    public function label(): string
    {
        return match ($this) {
            self::Masuk => 'Masuk',
            self::Keluar => 'Keluar',
            self::Tambah => 'Menambah',
            self::Ubah => 'Mengubah',
            self::UbahStatus => 'Mengubah Status',
            self::Hapus => 'Menghapus',
            self::Ajukan => 'Mengajukan',
            self::Setuju => 'Menyetujui',
            self::Tolak => 'Menolak',
            self::Batal => 'Membatalkan',
            self::Posting => 'Memposting',
            self::Terima => 'Mengonfirmasi Penerimaan',
            self::Unggah => 'Mengunggah',
            self::Unduh => 'Mengunduh',
        };
    }

    /**
     * Kelas badge TailAdmin untuk kolom Aksi.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Masuk, self::Tambah, self::Setuju, self::Terima, self::Posting => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Hapus, self::Tolak, self::Batal => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Ubah, self::UbahStatus => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Unggah, self::Unduh => 'bg-info-50 text-info-600 dark:bg-info-500/15 dark:text-info-400',
            self::Ajukan => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::Keluar => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
        };
    }

    /**
     * Aksi yang benar-benar mengubah data, dipakai rekap layar log.
     *
     * Masuk, keluar, dan unduh sengaja dikecualikan: ketiganya penting untuk
     * ditelusuri tetapi tidak meninggalkan perubahan apa pun pada data, dan
     * bila ikut dihitung akan menenggelamkan angka yang justru dicari
     * pemeriksa — berapa banyak data yang sungguh berubah pada satu periode.
     *
     * @return array<int, self>
     */
    public static function mengubahData(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $event) => ! in_array($event, [self::Masuk, self::Keluar, self::Unduh], true),
        ));
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown penyaring.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $event) => $carry + [$event->value => $event->label()],
            [],
        );
    }
}
