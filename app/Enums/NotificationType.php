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

    // --- Keadaan draf usulan pengadaan ---
    case DraftUsulanTersedia = 'draft_usulan_tersedia';

    // --- Keadaan usulan pengadaan ---
    case UsulanMenungguPersetujuan = 'usulan_menunggu_persetujuan';
    case UsulanMenggantung = 'usulan_menggantung';
    case UsulanBelumDirealisasi = 'usulan_belum_direalisasi';

    // --- Keadaan realisasi pengadaan ---
    case RealisasiMenungguPersetujuan = 'realisasi_menunggu_persetujuan';
    case RealisasiBelumDibelanjakan = 'realisasi_belum_dibelanjakan';
    case RealisasiMenungguPenerimaan = 'realisasi_menunggu_penerimaan';
    case BastBelumDiunggah = 'bast_belum_diunggah';

    // --- Keadaan usulan barang baru ---
    case UsulanBarangMenungguKeputusan = 'usulan_barang_menunggu_keputusan';

    // --- Peristiwa ---
    case OpnameDiposting = 'opname_diposting';
    case StokBergerakSetelahDicacah = 'stok_bergerak_setelah_dicacah';
    case MutasiManualSensitif = 'mutasi_manual_sensitif';
    case UsulanDisetujui = 'usulan_disetujui';
    case UsulanDitolak = 'usulan_ditolak';
    case PenerimaanDikonfirmasi = 'penerimaan_dikonfirmasi';
    case RealisasiDisetujui = 'realisasi_disetujui';
    case RealisasiDitolak = 'realisasi_ditolak';
    case RealisasiDibatalkan = 'realisasi_dibatalkan';
    case UsulanBarangDisetujui = 'usulan_barang_disetujui';
    case UsulanBarangDitolak = 'usulan_barang_ditolak';

    // --- Permintaan ---
    case PermintaanDiajukan = 'permintaan_diajukan';
    case PermintaanDisetujuiKepalaUnit = 'permintaan_disetujui_kepala_unit';
    case PermintaanDisetujuiPengelolaGudang = 'permintaan_disetujui_pengelola_gudang';
    case PermintaanDalamPengeluaran = 'permintaan_dalam_pengeluaran';
    case PermintaanDitolak = 'permintaan_ditolak';
    case PermintaanSelesai = 'permintaan_selesai';

    public function label(): string
    {
        return match ($this) {
            self::StokHabis => 'Stok Habis',
            self::StokMenipis => 'Stok Menipis',
            self::StokBerlebih => 'Stok Berlebih',
            self::BarangTidakBergerak => 'Barang Tidak Bergerak',
            self::DraftUsulanTersedia => 'Draf Usulan Tersedia',
            self::OpnameSiapDiposting => 'Opname Siap Diposting',
            self::OpnameDrafMenggantung => 'Draf Opname Menggantung',
            self::PeriodeBelumDiopname => 'Periode Belum Diopname',
            self::UsulanMenungguPersetujuan => 'Usulan Menunggu Persetujuan',
            self::UsulanMenggantung => 'Usulan Pengadaan Menggantung',
            self::UsulanBelumDirealisasi => 'Usulan Disetujui Belum Direalisasi',
            self::RealisasiMenungguPersetujuan => 'Realisasi Menunggu Persetujuan',
            self::RealisasiBelumDibelanjakan => 'Realisasi Belum Dibelanjakan',
            self::RealisasiMenungguPenerimaan => 'Realisasi Menunggu Penerimaan',
            self::BastBelumDiunggah => 'BAST Belum Diunggah',
            self::UsulanBarangMenungguKeputusan => 'Usulan Barang Baru Menunggu Keputusan',
            self::OpnameDiposting => 'Opname Diposting',
            self::StokBergerakSetelahDicacah => 'Stok Bergerak Setelah Dicacah',
            self::MutasiManualSensitif => 'Mutasi Manual Sensitif',
            self::UsulanDisetujui => 'Usulan Pengadaan Disetujui',
            self::UsulanDitolak => 'Usulan Pengadaan Ditolak',
            self::PenerimaanDikonfirmasi => 'Penerimaan Barang Dikonfirmasi',
            self::RealisasiDisetujui => 'Realisasi Pengadaan Disetujui',
            self::RealisasiDitolak => 'Realisasi Pengadaan Ditolak',
            self::RealisasiDibatalkan => 'Realisasi Pengadaan Dibatalkan',
            self::UsulanBarangDisetujui => 'Usulan Barang Baru Disetujui',
            self::UsulanBarangDitolak => 'Usulan Barang Baru Ditolak',
            self::PermintaanDiajukan => 'Permintaan Diajukan',
            self::PermintaanDisetujuiKepalaUnit => 'Permintaan Disetujui Kepala Unit',
            self::PermintaanDisetujuiPengelolaGudang => 'Permintaan Disetujui Pengelola Gudang',
            self::PermintaanDalamPengeluaran => 'Permintaan Dalam Proses Pengeluaran',
            self::PermintaanDitolak => 'Permintaan Ditolak',
            self::PermintaanSelesai => 'Permintaan Selesai',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::StokHabis => 'Barang tidak bersisa sehingga permintaan unit tidak dapat dilayani.',
            self::StokMenipis => 'Stok sudah menyentuh batas minimum yang ditetapkan.',
            self::StokBerlebih => 'Stok melampaui batas maksimum; berisiko menumpuk dan kedaluwarsa.',
            self::BarangTidakBergerak => 'Tidak ada mutasi apa pun dalam waktu lama; layak dievaluasi atau diarsipkan.',
            self::DraftUsulanTersedia => 'Draf usulan pengadaan otomatis sudah terkumpul dari pemantauan stok, menunggu ditinjau.',
            self::OpnameSiapDiposting => 'Seluruh barang sudah dicacah dan draf tinggal diposting.',
            self::OpnameDrafMenggantung => 'Draf opname dibiarkan terlalu lama tanpa diselesaikan.',
            self::PeriodeBelumDiopname => 'Periode berjalan hampir berakhir tetapi opname belum dibuat.',
            self::UsulanMenungguPersetujuan => 'Usulan pengadaan baru menunggu keputusan Pejabat Pengadaan.',
            self::UsulanMenggantung => 'Usulan pengadaan sudah lama diajukan tanpa disetujui maupun ditolak.',
            self::UsulanBelumDirealisasi => 'Usulan sudah disetujui tetapi belum ditindaklanjuti menjadi realisasi pengadaan.',
            self::RealisasiMenungguPersetujuan => 'Realisasi pengadaan menunggu keputusan Kasubbag Umum dan TI sebelum barang dipesan.',
            self::RealisasiBelumDibelanjakan => 'Realisasi pengadaan sudah lama disetujui tetapi belum ditandai sudah dibelanjakan.',
            self::RealisasiMenungguPenerimaan => 'Barang pengadaan sudah dibelanjakan dan menunggu konfirmasi diterima Pengelola Gudang.',
            self::BastBelumDiunggah => 'Barang sudah diterima tetapi berkas BAST bertanda tangan belum diunggah.',
            self::UsulanBarangMenungguKeputusan => 'Ada usulan barang baru yang menunggu diperiksa dan didaftarkan Pengelola Gudang.',
            self::OpnameDiposting => 'Penyesuaian hasil opname sudah diterapkan ke stok.',
            self::StokBergerakSetelahDicacah => 'Stok berubah di antara pencacahan dan posting, sehingga penyesuaian berbeda dari hasil cacah.',
            self::MutasiManualSensitif => 'Mutasi manual yang mengurangi stok tanpa dokumen sumber.',
            self::UsulanDisetujui => 'Usulan pengadaan yang diajukan telah disetujui, sebagian atau seluruhnya.',
            self::UsulanDitolak => 'Usulan pengadaan yang diajukan tidak dilanjutkan ke realisasi.',
            self::PenerimaanDikonfirmasi => 'Barang pengadaan telah dikonfirmasi diterima gudang; stok sudah bertambah.',
            self::RealisasiDisetujui => 'Realisasi pengadaan disetujui Kasubbag Umum dan TI; barang sudah boleh dibelanjakan.',
            self::RealisasiDitolak => 'Realisasi pengadaan ditolak Kasubbag Umum dan TI; masih dapat diperbaiki lalu diajukan ulang.',
            self::RealisasiDibatalkan => 'Realisasi pengadaan tidak dilanjutkan, sehingga barangnya tidak akan datang.',
            self::UsulanBarangDisetujui => 'Barang yang diusulkan sudah didaftarkan di master barang dan siap diadakan.',
            self::UsulanBarangDitolak => 'Barang yang diusulkan tidak didaftarkan; alasannya tercatat pada keputusan.',
            self::PermintaanDiajukan => 'Permintaan barang dari unit kerja telah diajukan ke gudang.',
            self::PermintaanDisetujuiKepalaUnit => 'Permintaan barang dari unit kerja telah disetujui oleh Kepala Unit.',
            self::PermintaanDisetujuiPengelolaGudang => 'Permintaan barang dari unit kerja telah disetujui oleh Pengelola Gudang dan siap diambil/dikirim.',
            self::PermintaanDalamPengeluaran => 'Permintaan barang dari unit kerja sedang dalam proses pengeluaran dari gudang.',
            self::PermintaanDitolak => 'Permintaan barang dari unit kerja tidak disetujui.',
            self::PermintaanSelesai => 'Permintaan barang dari unit kerja telah selesai diproses dan ditutup.',
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
            self::UsulanDitolak,
            self::PenerimaanDikonfirmasi,
            self::RealisasiDisetujui,
            self::RealisasiDitolak,
            self::RealisasiDibatalkan,
            self::UsulanBarangDisetujui,
            self::UsulanBarangDitolak => false,
            self::PermintaanDiajukan,
            self::PermintaanDisetujuiKepalaUnit,
            self::PermintaanDisetujuiPengelolaGudang,
            self::PermintaanDalamPengeluaran,
            self::PermintaanDitolak,
            self::PermintaanSelesai => false,
            default => true,
        };
    }

    public function level(): NotificationLevel
    {
        return match ($this) {
            self::StokHabis => NotificationLevel::Mendesak,
            self::StokMenipis,
            self::DraftUsulanTersedia,
            self::OpnameSiapDiposting,
            self::OpnameDrafMenggantung,
            self::PeriodeBelumDiopname,
            self::StokBergerakSetelahDicacah,
            self::MutasiManualSensitif,
            self::UsulanMenungguPersetujuan,
            self::UsulanMenggantung,
            self::UsulanBelumDirealisasi,
            self::RealisasiMenungguPersetujuan,
            self::RealisasiBelumDibelanjakan,
            self::RealisasiMenungguPenerimaan,
            self::BastBelumDiunggah,
            self::UsulanBarangMenungguKeputusan,
            self::UsulanBarangDitolak,
            self::UsulanDitolak,
            self::RealisasiDitolak,
            self::RealisasiDibatalkan => NotificationLevel::Perhatian,
            self::PermintaanDiajukan => NotificationLevel::Perhatian,
            self::PermintaanDisetujuiKepalaUnit => NotificationLevel::Perhatian,
            self::PermintaanDisetujuiPengelolaGudang => NotificationLevel::Perhatian,
            self::PermintaanDalamPengeluaran => NotificationLevel::Perhatian,
            self::PermintaanDitolak => NotificationLevel::Info,
            self::PermintaanSelesai => NotificationLevel::Info,
            self::StokBerlebih,
            self::BarangTidakBergerak,
            self::OpnameDiposting,
            self::UsulanDisetujui,
            self::UsulanBarangDisetujui,
            self::RealisasiDisetujui,
            self::PenerimaanDikonfirmasi => NotificationLevel::Info,
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
