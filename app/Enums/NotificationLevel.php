<?php

namespace App\Enums;

/**
 * Tingkat kepentingan notifikasi, disimpan pada `user_notifications.level`.
 *
 * Dipakai untuk mengurutkan isi lonceng: yang mendesak selalu di atas, supaya
 * pengguna yang hanya sempat membaca satu baris membaca baris yang paling
 * layak ditindak.
 */
enum NotificationLevel: string
{
    case Mendesak = 'mendesak';
    case Perhatian = 'perhatian';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Mendesak => 'Mendesak',
            self::Perhatian => 'Perlu Perhatian',
            self::Info => 'Informasi',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Mendesak => 'Menghambat layanan bila dibiarkan; tindak hari ini juga.',
            self::Perhatian => 'Belum menghambat, tetapi akan menjadi masalah bila diabaikan.',
            self::Info => 'Kabar yang perlu diketahui, tanpa tenggat.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Mendesak => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            self::Perhatian => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::Info => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
        };
    }

    /**
     * Warna pekat untuk titik penanda, di mana latar pucat `badgeClass()`
     * tidak akan terlihat.
     */
    public function dotClass(): string
    {
        return match ($this) {
            self::Mendesak => 'bg-error-500',
            self::Perhatian => 'bg-orange-400',
            self::Info => 'bg-brand-500',
        };
    }

    /**
     * Bobot pengurutan; makin kecil makin dahulu tampil.
     */
    public function bobot(): int
    {
        return match ($this) {
            self::Mendesak => 0,
            self::Perhatian => 1,
            self::Info => 2,
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown penyaring.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $level) => $carry + [$level->value => $level->label()],
            [],
        );
    }
}
