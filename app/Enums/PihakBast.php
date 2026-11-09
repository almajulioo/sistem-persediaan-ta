<?php

namespace App\Enums;

/**
 * Pihak penanda tangan BAST pengadaan, disimpan pada
 * `procurement_receipt_signatures.party`.
 *
 * Serah terimanya internal (ADR 0042): PIHAK PERTAMA adalah Pelaksana Belanja
 * yang menyerahkan barang, PIHAK KEDUA adalah Petugas Persediaan yang
 * menerimanya. Pihaknya diturunkan dari peran penanda tangan di server, tidak
 * pernah diambil dari formulir — satu peran, satu kolom tanda tangan
 * (ADR 0051).
 */
enum PihakBast: string
{
    case Pertama = 'pertama';
    case Kedua = 'kedua';

    public function label(): string
    {
        return match ($this) {
            self::Pertama => 'Pihak Pertama',
            self::Kedua => 'Pihak Kedua',
        };
    }

    /**
     * Sebutan di atas kolom tanda tangan, persis seperti format resmi.
     */
    public function jabatanTtd(): string
    {
        return match ($this) {
            self::Pertama => 'Pelaksana Belanja',
            self::Kedua => 'Petugas Persediaan',
        };
    }

    /**
     * Peran yang menandatangani sebagai pihak ini.
     */
    public function peran(): UserRole
    {
        return match ($this) {
            self::Pertama => UserRole::PejabatPengadaan,
            self::Kedua => UserRole::PengelolaGudang,
        };
    }

    /**
     * Pihak yang diwakili sebuah peran, atau null bila peran itu tidak
     * menandatangani BAST pengadaan — Admin termasuk (ADR 0051).
     */
    public static function dariPeran(?UserRole $role): ?self
    {
        foreach (self::cases() as $pihak) {
            if ($pihak->peran() === $role) {
                return $pihak;
            }
        }

        return null;
    }

    /**
     * Nilai penyaring `tte` pada daftar realisasi — mis. `pihak_pertama`.
     */
    public function nilaiPenyaring(): string
    {
        return 'pihak_'.$this->value;
    }

    public static function dariPenyaring(string $nilai): ?self
    {
        return str_starts_with($nilai, 'pihak_') ? self::tryFrom(substr($nilai, 6)) : null;
    }
}
