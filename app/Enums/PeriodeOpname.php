<?php

namespace App\Enums;

/**
 * Periode pelaksanaan stok opname, disimpan pada `stock_opnames.period_type`.
 *
 * Dipasangkan dengan kolom `period_year` sehingga judul dokumen terbentuk
 * sendiri, mis. "Stok Opname Triwulan III 2026".
 */
enum PeriodeOpname: string
{
    case TriwulanI = 'triwulan_1';
    case TriwulanII = 'triwulan_2';
    case TriwulanIII = 'triwulan_3';
    case TriwulanIV = 'triwulan_4';
    case SemesterI = 'semester_1';
    case SemesterII = 'semester_2';
    case Tahunan = 'tahunan';
    case Insidental = 'insidental';

    public function label(): string
    {
        return match ($this) {
            self::TriwulanI => 'Triwulan I',
            self::TriwulanII => 'Triwulan II',
            self::TriwulanIII => 'Triwulan III',
            self::TriwulanIV => 'Triwulan IV',
            self::SemesterI => 'Semester I',
            self::SemesterII => 'Semester II',
            self::Tahunan => 'Tahunan',
            self::Insidental => 'Insidental',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TriwulanI => 'Januari sampai Maret.',
            self::TriwulanII => 'April sampai Juni.',
            self::TriwulanIII => 'Juli sampai September.',
            self::TriwulanIV => 'Oktober sampai Desember.',
            self::SemesterI => 'Januari sampai Juni.',
            self::SemesterII => 'Juli sampai Desember.',
            self::Tahunan => 'Satu tahun anggaran penuh.',
            self::Insidental => 'Pencacahan di luar jadwal rutin.',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown form.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $periode) => $carry + [$periode->value => $periode->label()],
            [],
        );
    }
}
