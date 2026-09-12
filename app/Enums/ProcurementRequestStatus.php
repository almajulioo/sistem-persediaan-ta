<?php

namespace App\Enums;

/**
 * Status persetujuan usulan pengadaan, disimpan pada
 * `procurement_requests.status`.
 *
 * Terpisah dari `ProcurementStatus`: usulan dan realisasi adalah dua dokumen
 * berbeda. Usulan yang disetujui melahirkan satu `Procurement`, yang punya
 * alur statusnya sendiri.
 *
 * Sejak [ADR 0028](../../naren-documentations/keputusan/0028-status-usulan-disetujui-dipecah-dua.md),
 * "disetujui" bukan satu case, melainkan dua: `DisetujuiMenungguRealisasi`
 * (belum ada dokumen `Procurement` untuk usulan ini) dan `DisetujuiSelesai`
 * (sudah ada — apa pun status realisasinya, termasuk yang dibatalkan).
 * Perpindahan `MenungguRealisasi` → `Selesai` terjadi tepat satu kali, di
 * `ProcurementController::store()`, dan tidak pernah berbalik.
 */
enum ProcurementRequestStatus: string
{
    /**
     * Draf otomatis dari App\Support\ProcurementDraftScanner, belum diajukan
     * ke Pejabat Pengadaan. Sengaja dikecualikan dari options() dan dari
     * daftar/hitungan usulan di ProcurementRequestController::index() — draf
     * bukan dokumen usulan yang sebenarnya, hanya tampungan sementara.
     * Lihat ADR 0027.
     */
    case Draf = 'draf';
    case Diajukan = 'diajukan';
    case DisetujuiMenungguRealisasi = 'disetujui_menunggu_realisasi';
    case DisetujuiSelesai = 'disetujui_selesai';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Diajukan => 'Diajukan',
            self::DisetujuiMenungguRealisasi => 'Menunggu Realisasi',
            self::DisetujuiSelesai => 'Selesai',
            self::Ditolak => 'Ditolak',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draf => 'Draf otomatis dari pemantauan stok; belum diajukan ke Pejabat Pengadaan.',
            self::Diajukan => 'Menunggu persetujuan Pejabat Pengadaan.',
            self::DisetujuiMenungguRealisasi => 'Disetujui, belum ada realisasi yang dibuat untuk usulan ini.',
            self::DisetujuiSelesai => 'Disetujui dan sudah ada realisasi untuk usulan ini, apa pun nasib realisasinya.',
            self::Ditolak => 'Tidak dilanjutkan ke realisasi pengadaan.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draf => 'bg-gray-100 text-gray-600 dark:bg-gray-500/15 dark:text-gray-400',
            self::Diajukan => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            self::DisetujuiMenungguRealisasi => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::DisetujuiSelesai => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::Ditolak => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
        };
    }

    /**
     * Apakah status ini salah satu dari dua rupa "disetujui" — dipakai tempat
     * yang cuma peduli keputusannya positif, tanpa peduli sudah direalisasi
     * atau belum.
     */
    public function sudahDisetujui(): bool
    {
        return match ($this) {
            self::DisetujuiMenungguRealisasi, self::DisetujuiSelesai => true,
            default => false,
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown penyaring.
     *
     * Draf sengaja tidak ikut ditawarkan: ia bukan status yang bisa disaring
     * pada daftar usulan sungguhan (lihat ADR 0027).
     */
    public static function options(): array
    {
        return array_reduce(
            array_filter(self::cases(), fn (self $status) => $status !== self::Draf),
            fn (array $carry, self $status) => $carry + [$status->value => $status->label()],
            [],
        );
    }
}
