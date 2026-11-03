<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Palet kategori warna untuk badge, ubin, tab, dan garis penanda baris.
 * Model dan enum cukup menyebut nama warnanya (mis. `purple`), tampilan
 * mengambil kelasnya dari sini sehingga satu status berwarna sama di daftar,
 * detail, formulir, dan dasbor.
 *
 * Kelas ditulis utuh, bukan dirangkai dari nama warna, supaya terbaca
 * pemindai Tailwind. Nama yang tidak dikenal sengaja melempar galat supaya
 * salah ketik ketahuan di uji, sama dengan `x-ui.icon`.
 */
class Warna
{
    /**
     * lembut : latar muda + teks tua, untuk badge dan chip ikon
     * isi    : latar pekat, untuk ubin berikon putih
     * garis  : garis kiri penanda baris tabel
     * tab    : garis bawah dan teks tab yang aktif
     * teks   : warna ikon pada tab yang tidak aktif
     */
    private const PALET = [
        'gray' => [
            'lembut' => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
            'isi' => 'bg-gray-500 dark:bg-gray-600',
            'garis' => 'border-l-gray-300 dark:border-l-gray-700',
            'tab' => 'border-gray-500 text-gray-800 dark:border-gray-400 dark:text-white/90',
            'teks' => 'text-gray-400 dark:text-gray-500',
        ],
        'blue-light' => [
            'lembut' => 'bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-500',
            'isi' => 'bg-blue-light-500',
            'garis' => 'border-l-blue-light-500',
            'tab' => 'border-blue-light-500 text-blue-light-600 dark:text-blue-light-400',
            'teks' => 'text-blue-light-500',
        ],
        'sky' => [
            'lembut' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400',
            'isi' => 'bg-sky-500',
            'garis' => 'border-l-sky-500',
            'tab' => 'border-sky-500 text-sky-600 dark:text-sky-400',
            'teks' => 'text-sky-500 dark:text-sky-400',
        ],
        'brand' => [
            'lembut' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            'isi' => 'bg-brand-500',
            'garis' => 'border-l-brand-500',
            'tab' => 'border-brand-500 text-brand-600 dark:text-brand-400',
            'teks' => 'text-brand-500 dark:text-brand-400',
        ],
        'indigo' => [
            'lembut' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400',
            'isi' => 'bg-indigo-500',
            'garis' => 'border-l-indigo-500',
            'tab' => 'border-indigo-500 text-indigo-600 dark:text-indigo-400',
            'teks' => 'text-indigo-500 dark:text-indigo-400',
        ],
        'violet' => [
            'lembut' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400',
            'isi' => 'bg-violet-500',
            'garis' => 'border-l-violet-500',
            'tab' => 'border-violet-500 text-violet-600 dark:text-violet-400',
            'teks' => 'text-violet-500 dark:text-violet-400',
        ],
        'purple' => [
            'lembut' => 'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-400',
            'isi' => 'bg-purple-500',
            'garis' => 'border-l-purple-500',
            'tab' => 'border-purple-500 text-purple-600 dark:text-purple-400',
            'teks' => 'text-purple-500 dark:text-purple-400',
        ],
        'pink' => [
            'lembut' => 'bg-pink-50 text-pink-600 dark:bg-pink-500/15 dark:text-pink-400',
            'isi' => 'bg-pink-500',
            'garis' => 'border-l-pink-500',
            'tab' => 'border-pink-500 text-pink-600 dark:text-pink-400',
            'teks' => 'text-pink-500 dark:text-pink-400',
        ],
        'rose' => [
            'lembut' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/15 dark:text-rose-400',
            'isi' => 'bg-rose-500',
            'garis' => 'border-l-rose-500',
            'tab' => 'border-rose-500 text-rose-600 dark:text-rose-400',
            'teks' => 'text-rose-500 dark:text-rose-400',
        ],
        'orange' => [
            'lembut' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400',
            'isi' => 'bg-orange-500',
            'garis' => 'border-l-orange-500',
            'tab' => 'border-orange-500 text-orange-600 dark:text-orange-400',
            'teks' => 'text-orange-500 dark:text-orange-400',
        ],
        'amber' => [
            'lembut' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
            'isi' => 'bg-amber-500',
            'garis' => 'border-l-amber-500',
            'tab' => 'border-amber-500 text-amber-600 dark:text-amber-400',
            'teks' => 'text-amber-500 dark:text-amber-400',
        ],
        'yellow' => [
            'lembut' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-400',
            'isi' => 'bg-yellow-500',
            'garis' => 'border-l-yellow-400',
            'tab' => 'border-yellow-500 text-yellow-700 dark:text-yellow-400',
            'teks' => 'text-yellow-500 dark:text-yellow-400',
        ],
        'warning' => [
            'lembut' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            'isi' => 'bg-warning-500',
            'garis' => 'border-l-warning-500',
            'tab' => 'border-warning-500 text-warning-600 dark:text-orange-400',
            'teks' => 'text-warning-500',
        ],
        'success' => [
            'lembut' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            'isi' => 'bg-success-500',
            'garis' => 'border-l-success-500',
            'tab' => 'border-success-500 text-success-600 dark:text-success-500',
            'teks' => 'text-success-500',
        ],
        'error' => [
            'lembut' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            'isi' => 'bg-error-500',
            'garis' => 'border-l-error-500',
            'tab' => 'border-error-500 text-error-600 dark:text-error-500',
            'teks' => 'text-error-500',
        ],
        'teal' => [
            'lembut' => 'bg-teal-50 text-teal-600 dark:bg-teal-500/15 dark:text-teal-400',
            'isi' => 'bg-teal-500',
            'garis' => 'border-l-teal-500',
            'tab' => 'border-teal-500 text-teal-600 dark:text-teal-400',
            'teks' => 'text-teal-500 dark:text-teal-400',
        ],
    ];

    public static function lembut(string $warna): string
    {
        return self::ambil($warna, 'lembut');
    }

    public static function isi(string $warna): string
    {
        return self::ambil($warna, 'isi');
    }

    public static function garis(string $warna): string
    {
        return self::ambil($warna, 'garis');
    }

    public static function tab(string $warna): string
    {
        return self::ambil($warna, 'tab');
    }

    public static function teks(string $warna): string
    {
        return self::ambil($warna, 'teks');
    }

    /**
     * @return list<string>
     */
    public static function nama(): array
    {
        return array_keys(self::PALET);
    }

    private static function ambil(string $warna, string $varian): string
    {
        return self::PALET[$warna][$varian]
            ?? throw new InvalidArgumentException("Warna [{$warna}] tidak dikenal.");
    }
}
