<?php

namespace App\Enums;

/**
 * Jenis berkas pendukung pengadaan, disimpan pada
 * `procurement_documents.type`.
 *
 * Baru dua jenis karena memang itu yang dipakai di lapangan saat ini. Kolomnya
 * string biasa, jadi menambah jenis baru cukup menambah case di sini — tanpa
 * migrasi.
 *
 * BAST diperlakukan sebagai berkas biasa di tabel ini, bukan kolom khusus pada
 * `procurement_receipts`: yang memicu stok bertambah adalah **peristiwa**
 * penerimaannya, bukan ada tidaknya berkas pindaian. Lihat ADR 0018.
 */
enum ProcurementDocumentType: string
{
    case Bast = 'bast';
    case SpkKontrak = 'spk_kontrak';

    public function label(): string
    {
        return match ($this) {
            self::Bast => 'BAST Bertanda Tangan',
            self::SpkKontrak => 'SPK / Kontrak',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bast => 'Berita Acara Serah Terima yang sudah dicetak, ditandatangani, lalu dipindai.',
            self::SpkKontrak => 'Surat Perintah Kerja, kontrak, atau surat pesanan kepada penyedia.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Bast => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::SpkKontrak => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $type) => $carry + [$type->value => $type->label()],
            [],
        );
    }
}
