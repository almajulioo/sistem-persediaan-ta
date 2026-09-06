<?php

namespace App\Support;

use App\Enums\MutationType;
use App\Enums\NotificationType;
use App\Enums\PeriodeOpname;
use App\Enums\StockStatus;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\OpnameDetail;
use App\Models\ProcurementRequest;
use App\Models\StockMutation;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Menerjemahkan keadaan gudang menjadi notifikasi.
 *
 * Seluruh method `periksa*` bersifat idempoten: ia menyatakan keadaan apa yang
 * sedang benar, bukan menambahkan baris. Menjalankannya dua kali berturut-turut
 * tidak menghasilkan apa pun yang berbeda dari menjalankannya sekali.
 *
 * Sifat itu punya konsekuensi rancangan yang penting: pemeriksaan reaktif —
 * yang dipanggil dari controller setelah stok berubah — hanyalah **percepatan**,
 * bukan syarat kebenaran. Bila sebuah jalur perubahan stok lupa memanggilnya
 * (misalnya modul lain yang memakai StockLedger), notifikasinya tetap muncul
 * pada pemeriksaan terjadwal berikutnya. Yang hilang hanya kesegeraan, bukan
 * datanya.
 *
 * Karena itu hook tidak dipasang di dalam StockLedger. Selain menjaga kontrak
 * lintas sub sistem itu tetap sesederhana mungkin, `DB::afterCommit()` tidak
 * pernah dijalankan di bawah RefreshDatabase — seluruh uji dibungkus transaksi
 * yang tidak pernah commit — sehingga fiturnya justru menjadi tak teruji.
 */
class NotificationScanner
{
    /** Draf opname dianggap menggantung setelah sekian hari tanpa selesai. */
    private const HARI_DRAF_MENGGANTUNG = 7;

    /** Batas barang dianggap tidak bergerak. */
    private const HARI_TIDAK_BERGERAK = 180;

    /** Seberapa awal periode yang akan berakhir mulai diingatkan. */
    private const HARI_JELANG_AKHIR_PERIODE = 30;

    /** Usulan pengadaan dianggap menggantung setelah sekian hari belum diputuskan. */
    private const HARI_USULAN_MENGGANTUNG = 7;

    /**
     * Jenis mutasi manual yang mengurangi stok tanpa dokumen sumber. Pada
     * instansi pemeriksa, justru inilah yang paling layak diketahui Admin.
     */
    private const MUTASI_SENSITIF = [
        MutationType::BarangRusak,
        MutationType::BarangHilang,
        MutationType::Kadaluarsa,
        MutationType::KoreksiPencatatan,
    ];

    /**
     * Seluruh pemeriksaan berkala. Dipanggil perintah `notifikasi:periksa`.
     */
    public static function periksaSemua(): void
    {
        self::periksaSeluruhBarang();
        self::periksaBarangTidakBergerak();
        self::periksaOpname();
        self::periksaPeriodeOpname();
        self::periksaSeluruhUsulan();
    }

    /**
     * Keadaan stok satu barang. Dipanggil setelah stok atau ambang batasnya
     * berubah supaya notifikasinya muncul dan menghilang seketika.
     *
     * @param  EloquentCollection<int, User>|null  $penerima
     */
    public static function periksaBarang(Item $item, ?EloquentCollection $penerima = null): void
    {
        $penerima ??= self::pengawasGudang();

        // Barang yang diarsipkan tidak lagi dilayani, jadi kekurangan stoknya
        // bukan lagi hal yang perlu ditindak. Keadaan yang tersisa ditutup.
        $status = $item->is_active ? $item->stockStatus() : null;

        $nama = "{$item->item_code} — {$item->item_name}";
        $satuan = $item->unit->label();
        $tautan = route('items.show', $item, false);

        Notifier::terapkanKeadaan(
            berlaku: $status === StockStatus::Habis,
            penerima: $penerima,
            type: NotificationType::StokHabis,
            dedupKey: NotificationType::StokHabis->dedupKey($item->item_id),
            title: "Stok habis: {$item->item_name}",
            body: "{$nama} tidak bersisa sehingga permintaan unit tidak dapat dilayani. Ajukan usulan pengadaan, atau arsipkan barang bila memang tidak dipakai lagi.",
            actionUrl: $tautan,
            subject: $item,
        );

        Notifier::terapkanKeadaan(
            berlaku: $status === StockStatus::Menipis,
            penerima: $penerima,
            type: NotificationType::StokMenipis,
            dedupKey: NotificationType::StokMenipis->dedupKey($item->item_id),
            title: "Stok menipis: {$item->item_name}",
            body: sprintf(
                '%s tersisa %s %s, sudah menyentuh batas minimum %s %s. Pertimbangkan usulan pengadaan sebelum habis.',
                $nama,
                number_format($item->stock_current, 0, ',', '.'),
                $satuan,
                number_format($item->stock_min, 0, ',', '.'),
                $satuan,
            ),
            actionUrl: $tautan,
            subject: $item,
        );

        Notifier::terapkanKeadaan(
            berlaku: $status === StockStatus::Berlebih,
            penerima: $penerima,
            type: NotificationType::StokBerlebih,
            dedupKey: NotificationType::StokBerlebih->dedupKey($item->item_id),
            title: "Stok berlebih: {$item->item_name}",
            body: sprintf(
                '%s tersimpan %s %s, melampaui batas maksimum %s %s. Tinjau kembali kebutuhan supaya tidak menumpuk.',
                $nama,
                number_format($item->stock_current, 0, ',', '.'),
                $satuan,
                number_format($item->stock_max, 0, ',', '.'),
                $satuan,
            ),
            actionUrl: $tautan,
            subject: $item,
        );
    }

    /**
     * Keadaan stok seluruh barang, termasuk yang sudah diarsipkan supaya
     * notifikasi lamanya ikut ditutup.
     */
    public static function periksaSeluruhBarang(): void
    {
        $penerima = self::pengawasGudang();

        Item::query()->orderBy('item_code')->chunk(200, function ($items) use ($penerima) {
            foreach ($items as $item) {
                self::periksaBarang($item, $penerima);
            }
        });
    }

    /**
     * Barang bersisa yang tidak pernah bermutasi dalam waktu lama — calon
     * barang mati yang layak dievaluasi atau diarsipkan.
     */
    public static function periksaBarangTidakBergerak(): void
    {
        $penerima = self::pengawasGudang();
        $batas = now()->subDays(self::HARI_TIDAK_BERGERAK);

        // Barang yang stoknya nol sengaja dikecualikan: ia sudah ditandai
        // sebagai stok habis, dan dua notifikasi untuk satu barang hanya
        // menambah bising tanpa menambah informasi.
        $mati = Item::query()
            ->aktif()
            ->where('stock_current', '>', 0)
            ->where('created_at', '<', $batas)
            ->whereDoesntHave('stockMutations', fn ($query) => $query->where('created_at', '>=', $batas))
            ->pluck('item_id')
            ->all();

        Item::query()->where('stock_current', '>', 0)->chunk(200, function ($items) use ($penerima, $mati) {
            foreach ($items as $item) {
                Notifier::terapkanKeadaan(
                    berlaku: in_array($item->item_id, $mati, true),
                    penerima: $penerima,
                    type: NotificationType::BarangTidakBergerak,
                    dedupKey: NotificationType::BarangTidakBergerak->dedupKey($item->item_id),
                    title: "Barang tidak bergerak: {$item->item_name}",
                    body: sprintf(
                        '%s — %s tidak mengalami mutasi apa pun selama lebih dari %d bulan, padahal masih tersimpan %s %s. Tinjau apakah barang ini masih dibutuhkan.',
                        $item->item_code,
                        $item->item_name,
                        (int) round(self::HARI_TIDAK_BERGERAK / 30),
                        number_format($item->stock_current, 0, ',', '.'),
                        $item->unit->label(),
                    ),
                    actionUrl: route('items.show', $item, false),
                    subject: $item,
                );
            }
        });
    }

    /**
     * Keadaan tiap dokumen opname: siap diposting, atau menggantung terlalu
     * lama. Opname yang sudah selesai menutup keduanya.
     */
    public static function periksaOpname(): void
    {
        $admin = Notifier::penerimaBerperan(UserRole::Admin);
        $batas = now()->subDays(self::HARI_DRAF_MENGGANTUNG);

        StockOpname::query()->with('user')->chunk(100, function ($opnames) use ($admin, $batas) {
            foreach ($opnames as $opname) {
                $siap = $opname->siapDiposting();

                // Menggantung dan siap diposting dibuat saling eksklusif: draf
                // yang sudah lengkap butuh satu ajakan yang jelas, bukan dua
                // notifikasi tentang dokumen yang sama.
                $menggantung = $opname->isDraf() && ! $siap && $opname->created_at->lt($batas);

                $pembuat = $opname->user;
                $penerimaSiap = $pembuat !== null ? new EloquentCollection([$pembuat]) : new EloquentCollection;
                $penerimaMenggantung = $pembuat !== null
                    ? $admin->concat([$pembuat])->unique('user_id')
                    : $admin;

                $tautan = route('opname.edit', $opname, false);

                Notifier::terapkanKeadaan(
                    berlaku: $siap,
                    penerima: $penerimaSiap,
                    type: NotificationType::OpnameSiapDiposting,
                    dedupKey: NotificationType::OpnameSiapDiposting->dedupKey($opname->opname_id),
                    title: "Siap diposting: {$opname->judul()}",
                    body: "Seluruh barang pada {$opname->judul()} sudah dicacah. Periksa sekali lagi lalu posting supaya penyesuaian masuk ke buku mutasi.",
                    actionUrl: $tautan,
                    subject: $opname,
                );

                Notifier::terapkanKeadaan(
                    berlaku: $menggantung,
                    penerima: $penerimaMenggantung,
                    type: NotificationType::OpnameDrafMenggantung,
                    dedupKey: NotificationType::OpnameDrafMenggantung->dedupKey($opname->opname_id),
                    title: "Draf menggantung: {$opname->judul()}",
                    body: sprintf(
                        'Draf %s dibuat %s dan masih menyisakan %d barang yang belum dicacah. Lanjutkan pencacahan atau hapus drafnya.',
                        $opname->judul(),
                        $opname->created_at->diffForHumans(),
                        $opname->ringkasan()['belum_dicacah'],
                    ),
                    actionUrl: $tautan,
                    subject: $opname,
                );
            }
        });
    }

    /**
     * Triwulan berjalan yang akan atau sudah berakhir tanpa dokumen opname.
     *
     * Hanya triwulan yang diperiksa: semester, tahunan, dan insidental tidak
     * punya jadwal yang bisa disimpulkan sendiri oleh sistem.
     */
    public static function periksaPeriodeOpname(): void
    {
        $penerima = self::pengawasGudang();
        $tahun = (int) now()->year;

        foreach (self::akhirTriwulan($tahun) as $periode => $akhir) {
            $periode = PeriodeOpname::from($periode);

            // Triwulan yang belum mendekati akhirnya belum perlu diingatkan.
            $jatuhTempo = now()->startOfDay()
                ->gte($akhir->copy()->subDays(self::HARI_JELANG_AKHIR_PERIODE));

            $sudahAda = StockOpname::query()
                ->where('period_type', $periode)
                ->where('period_year', $tahun)
                ->exists();

            $terlambat = now()->startOfDay()->gt($akhir);

            Notifier::terapkanKeadaan(
                berlaku: $jatuhTempo && ! $sudahAda,
                penerima: $penerima,
                type: NotificationType::PeriodeBelumDiopname,
                dedupKey: NotificationType::PeriodeBelumDiopname->dedupKey("{$tahun}:{$periode->value}"),
                title: sprintf('Opname %s %d belum dibuat', $periode->label(), $tahun),
                body: sprintf(
                    '%s %d (%s) %s pada %s dan belum ada dokumen stok opname. Buat drafnya agar pencacahan sempat diselesaikan.',
                    $periode->label(),
                    $tahun,
                    rtrim($periode->description(), '.'),
                    $terlambat ? 'sudah berakhir' : 'akan berakhir',
                    $akhir->translatedFormat('j F Y'),
                ),
                actionUrl: route('opname.create', absolute: false),
            );
        }
    }

    /**
     * Keadaan satu usulan pengadaan: menunggu persetujuan, atau sudah
     * menggantung terlalu lama tanpa diputuskan. Dipanggil setelah usulan
     * diajukan atau diputuskan supaya notifikasinya muncul dan menghilang
     * seketika.
     *
     * Kedua keadaan dibuat saling eksklusif seperti pasangan
     * OpnameSiapDiposting/OpnameDrafMenggantung: usulan yang sama tidak perlu
     * dua notifikasi berbeda tentang dokumen yang belum diputuskan.
     *
     * @param  EloquentCollection<int, User>|null  $penerima
     */
    public static function periksaUsulan(ProcurementRequest $procurementRequest, ?EloquentCollection $penerima = null): void
    {
        $penerima ??= self::pejabatPengadaan();

        $menunggu = $procurementRequest->isDiajukan();
        $kadaluarsa = $procurementRequest->created_at->lt(now()->subDays(self::HARI_USULAN_MENGGANTUNG));

        $judul = $procurementRequest->judul();
        $tautan = route('procurement-requests.show', $procurementRequest, false);
        $pengaju = $procurementRequest->user;

        Notifier::terapkanKeadaan(
            berlaku: $menunggu && ! $kadaluarsa,
            penerima: $penerima,
            type: NotificationType::UsulanMenungguPersetujuan,
            dedupKey: NotificationType::UsulanMenungguPersetujuan->dedupKey($procurementRequest->procurement_request_id),
            title: "Usulan baru menunggu persetujuan: {$judul}",
            body: sprintf(
                '%s diajukan oleh %s dan menunggu keputusan Anda.',
                $judul,
                $pengaju?->fullname ?? 'pengguna yang sudah tidak aktif',
            ),
            actionUrl: $tautan,
            subject: $procurementRequest,
        );

        // Pengaju ikut diberi tahu begitu usulannya sendiri menggantung —
        // ia paling berkepentingan usulannya segera diputuskan.
        $penerimaMenggantung = $pengaju !== null
            ? $penerima->concat([$pengaju])->unique('user_id')
            : $penerima;

        Notifier::terapkanKeadaan(
            berlaku: $menunggu && $kadaluarsa,
            penerima: $penerimaMenggantung,
            type: NotificationType::UsulanMenggantung,
            dedupKey: NotificationType::UsulanMenggantung->dedupKey($procurementRequest->procurement_request_id),
            title: "Usulan menggantung: {$judul}",
            body: sprintf(
                '%s diajukan %s dan masih belum disetujui maupun ditolak. Segera putuskan.',
                $judul,
                $procurementRequest->created_at->diffForHumans(),
            ),
            actionUrl: $tautan,
            subject: $procurementRequest,
        );
    }

    /**
     * Keadaan seluruh usulan pengadaan. Menyapu seluruh baris, bukan hanya
     * yang berstatus Diajukan, supaya usulan yang baru diputuskan tetapi
     * pemeriksaan reaktifnya sempat gagal tetap ikut ditutup di sini.
     */
    public static function periksaSeluruhUsulan(): void
    {
        $penerima = self::pejabatPengadaan();

        ProcurementRequest::query()->with('user')->chunk(100, function ($usulan) use ($penerima) {
            foreach ($usulan as $procurementRequest) {
                self::periksaUsulan($procurementRequest, $penerima);
            }
        });
    }

    /**
     * Peristiwa: usulan pengadaan disetujui atau ditolak.
     *
     * Pelakunya sendiri tidak diberi tahu. Bila pengaju sudah diarsipkan
     * (akun tidak aktif), tidak ada yang perlu diberi tahu.
     */
    public static function catatUsulanDiputuskan(ProcurementRequest $procurementRequest, User $pelaku): void
    {
        $pengaju = $procurementRequest->user;

        if ($pengaju === null || $pengaju->user_id === $pelaku->user_id) {
            return;
        }

        $judul = $procurementRequest->judul();
        $tautan = route('procurement-requests.show', $procurementRequest, false);
        $ringkasan = $procurementRequest->ringkasan();

        if ($procurementRequest->isDisetujui()) {
            Notifier::catatPeristiwa(
                penerima: new EloquentCollection([$pengaju]),
                type: NotificationType::UsulanDisetujui,
                dedupKey: NotificationType::UsulanDisetujui->dedupKey($procurementRequest->procurement_request_id),
                title: "{$judul} disetujui",
                body: sprintf(
                    '%s menyetujui %s: %d dari %d barang disetujui, total %s dari %s unit yang diminta.',
                    $pelaku->fullname,
                    $judul,
                    $ringkasan['barang_disetujui'],
                    $ringkasan['barang'],
                    number_format($ringkasan['disetujui'], 0, ',', '.'),
                    number_format($ringkasan['diminta'], 0, ',', '.'),
                ),
                actionUrl: $tautan,
                subject: $procurementRequest,
            );
        } elseif ($procurementRequest->isDitolak()) {
            Notifier::catatPeristiwa(
                penerima: new EloquentCollection([$pengaju]),
                type: NotificationType::UsulanDitolak,
                dedupKey: NotificationType::UsulanDitolak->dedupKey($procurementRequest->procurement_request_id),
                title: "{$judul} ditolak",
                body: sprintf(
                    '%s menolak %s. Periksa kembali kebutuhan atau ajukan usulan baru bila masih diperlukan.',
                    $pelaku->fullname,
                    $judul,
                ),
                actionUrl: $tautan,
                subject: $procurementRequest,
            );
        }

        self::periksaUsulan($procurementRequest);
    }

    /**
     * Peristiwa: opname selesai diposting.
     *
     * Dipanggil sekali per posting, bukan sekali per barang, sehingga posting
     * yang menyentuh puluhan barang tetap menghasilkan satu baris ringkasan.
     */
    public static function catatOpnameDiposting(StockOpname $opname, User $pelaku): void
    {
        $ringkasan = $opname->ringkasan();

        Notifier::catatPeristiwa(
            penerima: self::pengawasGudang()->reject(
                fn (User $user) => $user->user_id === $pelaku->user_id,
            ),
            type: NotificationType::OpnameDiposting,
            dedupKey: NotificationType::OpnameDiposting->dedupKey($opname->opname_id),
            title: "{$opname->judul()} telah diposting",
            body: sprintf(
                '%s memposting %s: %d barang dicacah, %d selisih, %s lebih dan %s kurang. Penyesuaian sudah masuk ke buku mutasi.',
                $pelaku->fullname,
                $opname->judul(),
                $ringkasan['dicacah'],
                $ringkasan['selisih'],
                number_format($ringkasan['lebih'], 0, ',', '.'),
                number_format($ringkasan['kurang'], 0, ',', '.'),
            ),
            actionUrl: route('opname.show', $opname, false),
            subject: $opname,
        );

        self::catatStokBergerakSetelahDicacah($opname);
        self::periksaBarangTerdampak($opname);
    }

    /**
     * Selaraskan keadaan stok barang yang stoknya diubah oleh posting.
     *
     * Dilakukan sekaligus di sini, bukan per mutasi di dalam OpnamePoster,
     * supaya posting yang menyentuh puluhan barang tetap satu kali pemeriksaan
     * dengan satu kali pengambilan daftar penerima.
     */
    private static function periksaBarangTerdampak(StockOpname $opname): void
    {
        $terdampak = $opname->details()
            ->where('adjustment_amount', '!=', 0)
            ->pluck('item_id');

        if ($terdampak->isEmpty()) {
            return;
        }

        $penerima = self::pengawasGudang();

        Item::query()->whereIn('item_id', $terdampak)->each(
            fn (Item $item) => self::periksaBarang($item, $penerima),
        );
    }

    /**
     * Peristiwa: mutasi manual yang mengurangi stok tanpa dokumen sumber.
     *
     * Pelakunya sendiri tidak diberi tahu — ia baru saja melakukannya.
     */
    public static function catatMutasiManual(StockMutation $mutation, User $pelaku): void
    {
        if (! in_array($mutation->mutation_type, self::MUTASI_SENSITIF, true)) {
            return;
        }

        $mutation->loadMissing('item');

        Notifier::catatPeristiwa(
            penerima: Notifier::penerimaBerperan(UserRole::Admin)->reject(
                fn (User $user) => $user->user_id === $pelaku->user_id,
            ),
            type: NotificationType::MutasiManualSensitif,
            dedupKey: NotificationType::MutasiManualSensitif->dedupKey($mutation->mutation_id),
            title: "{$mutation->mutation_type->label()}: {$mutation->item->item_name}",
            body: sprintf(
                '%s mencatat %s sebanyak %s %s pada %s. Sisa stok %s. Keterangan: %s',
                $pelaku->fullname,
                strtolower($mutation->mutation_type->label()),
                $mutation->signedAmount(),
                $mutation->item->unit->label(),
                $mutation->item->item_code,
                number_format($mutation->stock_after, 0, ',', '.'),
                $mutation->notes ?: 'tidak diisi',
            ),
            actionUrl: route('items.kartu-persediaan', $mutation->item, false),
            subject: $mutation->item,
        );
    }

    /**
     * Peristiwa: stok sebuah barang bergerak di antara pencacahan dan posting,
     * sehingga penyesuaian yang diterapkan berbeda dari hasil cacah.
     *
     * Ini satu-satunya cara petugas mengetahuinya; laporan opname mencatatnya,
     * tetapi tidak ada yang memberitahukannya.
     */
    private static function catatStokBergerakSetelahDicacah(StockOpname $opname): void
    {
        $pembuat = $opname->user;

        if ($pembuat === null) {
            return;
        }

        $bergerak = $opname->details()
            ->with('item')
            ->get()
            ->filter(fn (OpnameDetail $detail) => $detail->stokBerubahSejakDicacah());

        if ($bergerak->isEmpty()) {
            return;
        }

        Notifier::catatPeristiwa(
            penerima: new EloquentCollection([$pembuat]),
            type: NotificationType::StokBergerakSetelahDicacah,
            dedupKey: NotificationType::StokBergerakSetelahDicacah->dedupKey($opname->opname_id),
            title: "Stok bergerak setelah dicacah pada {$opname->judul()}",
            body: sprintf(
                '%d barang (%s) mengalami mutasi setelah dicacah, sehingga stok akhirnya tidak sama dengan hasil cacah. Ini bukan kesalahan — transaksi yang sah memang tidak dibatalkan oleh opname. Periksa laporan bila perlu.',
                $bergerak->count(),
                $bergerak->take(3)->map(fn (OpnameDetail $d) => $d->item->item_code)->implode(', ')
                    .($bergerak->count() > 3 ? ', dan lainnya' : ''),
            ),
            actionUrl: route('opname.show', $opname, false),
            subject: $opname,
        );
    }

    /**
     * Pengelola Gudang beserta Admin — penerima baku urusan pergudangan.
     *
     * @return EloquentCollection<int, User>
     */
    private static function pengawasGudang(): EloquentCollection
    {
        return Notifier::penerimaBerperan(UserRole::PengelolaGudang, UserRole::Admin);
    }

    /**
     * Pejabat Pengadaan beserta Admin — penerima baku keputusan usulan.
     *
     * @return EloquentCollection<int, User>
     */
    private static function pejabatPengadaan(): EloquentCollection
    {
        return Notifier::penerimaBerperan(UserRole::PejabatPengadaan, UserRole::Admin);
    }

    /**
     * Tanggal akhir tiap triwulan pada tahun yang diminta.
     *
     * @return array<string, \Illuminate\Support\Carbon>
     */
    private static function akhirTriwulan(int $tahun): array
    {
        return [
            PeriodeOpname::TriwulanI->value => now()->setDate($tahun, 3, 31)->endOfDay(),
            PeriodeOpname::TriwulanII->value => now()->setDate($tahun, 6, 30)->endOfDay(),
            PeriodeOpname::TriwulanIII->value => now()->setDate($tahun, 9, 30)->endOfDay(),
            PeriodeOpname::TriwulanIV->value => now()->setDate($tahun, 12, 31)->endOfDay(),
        ];
    }
}
