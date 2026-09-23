<?php

namespace App\Enums;

/**
 * Jenis berkas pendukung pengadaan, disimpan pada
 * `procurement_documents.type`.
 *
 * Hanya jenis yang memang dipakai di lapangan. Kolomnya string biasa, jadi
 * menambah jenis baru cukup menambah case di sini — tanpa migrasi, seperti
 * `FotoBelanja` yang datang belakangan (ADR 0035).
 *
 * BAST diperlakukan sebagai berkas biasa di tabel ini, bukan kolom khusus pada
 * `procurement_receipts`: yang memicu stok bertambah adalah **peristiwa**
 * penerimaannya, bukan ada tidaknya berkas pindaian. Lihat ADR 0018.
 *
 * `FotoBelanja` justru kebalikannya: wajib ada minimal satu sebelum realisasi
 * boleh ditandai sudah dibelanjakan. Bedanya bukan soal jenis berkas, tetapi
 * kapan berkasnya lahir — BAST butuh tanda tangan orang lain lalu dipindai,
 * sedangkan foto belanja dibuat Pejabat Pengadaan sendiri saat belanja,
 * sehingga sudah ada ketika tombolnya ditekan. Lihat ADR 0035.
 */
enum ProcurementDocumentType: string
{
    case Bast = 'bast';
    case SpkKontrak = 'spk_kontrak';
    case FotoBelanja = 'foto_belanja';

    public function label(): string
    {
        return match ($this) {
            self::Bast => 'BAST Bertanda Tangan',
            self::SpkKontrak => 'SPK / Kontrak',
            self::FotoBelanja => 'Foto Belanja',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bast => 'Berita Acara Serah Terima yang sudah dicetak, ditandatangani, lalu dipindai.',
            self::SpkKontrak => 'Surat Perintah Kerja, kontrak, atau surat pesanan kepada penyedia.',
            self::FotoBelanja => 'Foto barang yang dibeli atau foto kegiatan belanjanya, sebagai bukti realisasi.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Bast => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            self::SpkKontrak => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            self::FotoBelanja => 'bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-500',
        };
    }

    /**
     * Format berkas yang diterima, untuk aturan `mimes`.
     *
     * Foto belanja hanya gambar, di jalur mana pun ia diunggah: ia syarat
     * menandai realisasi sudah dibelanjakan, dan PDF yang diunggah sebagai
     * "foto" akan meloloskan syarat itu tanpa satu pun foto (ADR 0035). Jenis
     * lain juga menerima PDF, karena hasil pindai lazimnya PDF.
     *
     * Tidak ada SVG maupun HTML di daftar mana pun: berkas dapat ditampilkan
     * langsung di peramban, dan keduanya dapat membawa skrip.
     */
    public function formatBerkas(): string
    {
        return match ($this) {
            self::FotoBelanja => 'jpg,jpeg,png,webp,heic,heif',
            default => 'pdf,jpg,jpeg,png,webp,heic,heif',
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
