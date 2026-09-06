<?php

namespace App\Enums;

/**
 * Jenis notifikasi, disimpan pada `user_notifications.type`.
 *
 * Enum ini memikul keputusan rancangan yang paling menentukan: setiap jenis
 * menyatakan dirinya **keadaan** atau **peristiwa** lewat `bersifatKeadaan()`.
 *
 * - **Keadaan** adalah sesuatu yang *sedang* benar, mis. "stok kertas habis".
 *   Ia boleh sembuh sendiri: begitu barang masuk, notifikasinya ditutup tanpa
 *   pengguna perlu mengklik apa pun. Satu baris bertahan selama keadaannya
 *   bertahan, berapa kali pun pemeriksaan dijalankan.
 * - **Peristiwa** adalah fakta historis, mis. "opname triwulan III diposting".
 *   Ia tidak pernah berubah dan tidak pernah ditutup sendiri; ia hilang dari
 *   lonceng setelah dibaca.
 *
 * Pemisahan ini yang menjaga lonceng tetap layak dibaca. Bila keadaan
 * diperlakukan sebagai peristiwa, satu barang yang habis akan melahirkan
 * notifikasi baru pada setiap mutasi sesudahnya, dan lonceng menjadi banjir
 * yang diabaikan orang dalam hitungan hari. Bila keadaan tidak pernah ditutup,
 * lonceng penuh kabar basi yang justru menyesatkan.
 *
 * Lihat App\Support\Notifier dan App\Support\NotificationScanner.
 */
enum NotificationType: string
{
    // --- Keadaan stok ---
    case StokHabis = 'stok_habis';
    case StokMenipis = 'stok_menipis';
    case StokBerlebih = 'stok_berlebih';
    case BarangTidakBergerak = 'barang_tidak_bergerak';

    // --- Keadaan stok opname ---
    case OpnameSiapDiposting = 'opname_siap_diposting';
    case OpnameDrafMenggantung = 'opname_draf_menggantung';
    case PeriodeBelumDiopname = 'periode_belum_diopname';

    // --- Keadaan usulan pengadaan ---
    case UsulanMenungguPersetujuan = 'usulan_menunggu_persetujuan';
    case UsulanMenggantung = 'usulan_menggantung';

    // --- Peristiwa ---
    case OpnameDiposting = 'opname_diposting';
    case StokBergerakSetelahDicacah = 'stok_bergerak_setelah_dicacah';
    case MutasiManualSensitif = 'mutasi_manual_sensitif';
    case UsulanDisetujui = 'usulan_disetujui';
    case UsulanDitolak = 'usulan_ditolak';

    public function label(): string
    {
        return match ($this) {
            self::StokHabis => 'Stok Habis',
            self::StokMenipis => 'Stok Menipis',
            self::StokBerlebih => 'Stok Berlebih',
            self::BarangTidakBergerak => 'Barang Tidak Bergerak',
            self::OpnameSiapDiposting => 'Opname Siap Diposting',
            self::OpnameDrafMenggantung => 'Draf Opname Menggantung',
            self::PeriodeBelumDiopname => 'Periode Belum Diopname',
            self::UsulanMenungguPersetujuan => 'Usulan Menunggu Persetujuan',
            self::UsulanMenggantung => 'Usulan Pengadaan Menggantung',
            self::OpnameDiposting => 'Opname Diposting',
            self::StokBergerakSetelahDicacah => 'Stok Bergerak Setelah Dicacah',
            self::MutasiManualSensitif => 'Mutasi Manual Sensitif',
            self::UsulanDisetujui => 'Usulan Pengadaan Disetujui',
            self::UsulanDitolak => 'Usulan Pengadaan Ditolak',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::StokHabis => 'Barang tidak bersisa sehingga permintaan unit tidak dapat dilayani.',
            self::StokMenipis => 'Stok sudah menyentuh batas minimum yang ditetapkan.',
            self::StokBerlebih => 'Stok melampaui batas maksimum; berisiko menumpuk dan kedaluwarsa.',
            self::BarangTidakBergerak => 'Tidak ada mutasi apa pun dalam waktu lama; layak dievaluasi atau diarsipkan.',
            self::OpnameSiapDiposting => 'Seluruh barang sudah dicacah dan draf tinggal diposting.',
            self::OpnameDrafMenggantung => 'Draf opname dibiarkan terlalu lama tanpa diselesaikan.',
            self::PeriodeBelumDiopname => 'Periode berjalan hampir berakhir tetapi opname belum dibuat.',
            self::UsulanMenungguPersetujuan => 'Usulan pengadaan baru menunggu keputusan Pejabat Pengadaan.',
            self::UsulanMenggantung => 'Usulan pengadaan sudah lama diajukan tanpa disetujui maupun ditolak.',
            self::OpnameDiposting => 'Penyesuaian hasil opname sudah diterapkan ke stok.',
            self::StokBergerakSetelahDicacah => 'Stok berubah di antara pencacahan dan posting, sehingga penyesuaian berbeda dari hasil cacah.',
            self::MutasiManualSensitif => 'Mutasi manual yang mengurangi stok tanpa dokumen sumber.',
            self::UsulanDisetujui => 'Usulan pengadaan yang diajukan telah disetujui, sebagian atau seluruhnya.',
            self::UsulanDitolak => 'Usulan pengadaan yang diajukan tidak dilanjutkan ke realisasi.',
        };
    }

    /**
     * Keadaan menutup diri saat kondisinya pulih; peristiwa tidak pernah ditutup.
     */
    public function bersifatKeadaan(): bool
    {
        return match ($this) {
            self::OpnameDiposting,
            self::StokBergerakSetelahDicacah,
            self::MutasiManualSensitif,
            self::UsulanDisetujui,
            self::UsulanDitolak => false,
            default => true,
        };
    }

    public function level(): NotificationLevel
    {
        return match ($this) {
            self::StokHabis => NotificationLevel::Mendesak,
            self::StokMenipis,
            self::OpnameSiapDiposting,
            self::OpnameDrafMenggantung,
            self::PeriodeBelumDiopname,
            self::StokBergerakSetelahDicacah,
            self::MutasiManualSensitif,
            self::UsulanMenungguPersetujuan,
            self::UsulanMenggantung,
            self::UsulanDitolak => NotificationLevel::Perhatian,
            self::StokBerlebih,
            self::BarangTidakBergerak,
            self::OpnameDiposting,
            self::UsulanDisetujui => NotificationLevel::Info,
        };
    }

    public function badgeClass(): string
    {
        return $this->level()->badgeClass();
    }

    /**
     * Kunci dedup baris notifikasi: satu baris per jenis per subjek.
     *
     * Bersama indeks unik (user_id, dedup_key) inilah yang membuat seluruh
     * pemeriksaan idempoten — boleh dijalankan berapa kali pun tanpa duplikasi.
     */
    public function dedupKey(string $subjek): string
    {
        return $this->value.':'.$subjek;
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown penyaring.
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
