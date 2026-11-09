<?php

namespace App\Support;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\PihakBast;
use App\Enums\UserRole;
use App\Exceptions\ProcurementTidakSiapException;
use App\Models\Procurement;
use App\Models\ProcurementDetail;
use App\Models\ProcurementReceipt;
use App\Models\ProcurementReceiptSignature;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Siklus BAST pengadaan digital (ADR 0051), dalam empat langkah yang semuanya
 * terjadi selama realisasi berstatus `MenungguPenerimaan`:
 *
 * 1. Pengelola Gudang mencatat **hasil pemeriksaan** — `catatPemeriksaan()`.
 * 2. Salah satu pihak mengisi **nomor BAST** — `isiNomor()`.
 * 3–4. Kedua pihak membubuhkan **TTE**, urutannya bebas — `tandatangani()`.
 *    TTE kedua memasukkan stok di transaksi yang sama.
 *
 * Mengubah hasil pemeriksaan, nomor, atau realisasinya setelah ada TTE
 * **mencabut** TTE itu (`cabut()`), pola yang sama dengan ADR 0030: menyimpan
 * perubahan berarti meminta tanda tangan ulang.
 *
 * Ini TTE tidak tersertifikasi (PP 71/2019 Pasal 59–60): QR-nya menunjuk
 * halaman verifikasi aplikasi ini, bukan sertifikat elektronik BSrE.
 *
 * Setiap aksi mengunci baris realisasinya, sehingga dua TTE yang hampir
 * bersamaan tidak bisa memasukkan stok dua kali. Audit dan notifikasi ditulis
 * di sini, di luar transaksi, mengikuti pola OpnamePoster.
 */
class BastPengadaan
{
    public const ALASAN_PEMERIKSAAN = 'Hasil pemeriksaan diubah';

    public const ALASAN_NOMOR = 'Nomor BAST diubah';

    public const ALASAN_REALISASI_DIUBAH = 'Realisasi diubah';

    public const ALASAN_REALISASI_DIBATALKAN = 'Realisasi dibatalkan';

    /**
     * Mencatat atau mengubah hasil pemeriksaan. Stok tidak berubah.
     *
     * Satu penerimaan per realisasi (indeks unik) dan upsert di bawah kunci:
     * mengirim ulang formulir yang sama hanya menimpa nilai yang sama, jadi
     * tidak perlu token kiriman ADR 0046.
     *
     * @param  array<string, int>  $jumlah  Peta procurement_detail_id => jumlah diterima.
     *
     * @throws ProcurementTidakSiapException
     */
    public static function catatPemeriksaan(
        Procurement $procurement,
        User $petugas,
        CarbonInterface $tanggal,
        array $jumlah,
        ?string $catatan,
    ): ProcurementReceipt {
        [$receipt, $baru, $berubah, $dicabut] = DB::transaction(function () use ($procurement, $petugas, $tanggal, $jumlah, $catatan) {
            $procurement = self::kunci($procurement);
            self::pastikanMenungguPenerimaan($procurement);

            $receipt = $procurement->receipt;
            $baru = $receipt === null;
            $catatan = filled($catatan) ? $catatan : null;

            $berubah = $baru
                || $receipt->received_at->toDateString() !== $tanggal->toDateString()
                || $receipt->notes !== $catatan;

            foreach ($procurement->details as $detail) {
                $nilai = (int) ($jumlah[$detail->procurement_detail_id] ?? 0);

                if ($detail->amount_checked !== $nilai) {
                    $berubah = true;
                    $detail->forceFill(['amount_checked' => $nilai])->save();
                }
            }

            if (! $berubah) {
                return [$receipt, false, false, []];
            }

            $receipt ??= new ProcurementReceipt(['procurement_id' => $procurement->procurement_id]);
            $receipt->fill([
                'received_by' => $petugas->user_id,
                'received_at' => $tanggal,
                'notes' => $catatan,
            ])->save();

            $dicabut = $baru ? [] : self::cabut($receipt, self::ALASAN_PEMERIKSAAN);

            return [$receipt, $baru, true, $dicabut];
        });

        if ($berubah) {
            AuditLogger::record(
                user: $petugas,
                activity: ($baru ? 'Mencatat' : 'Mengubah').' hasil pemeriksaan barang '.$procurement->judul()
                    .self::kalimatPencabutan($dicabut),
                module: AuditModule::Penerimaan,
                event: $baru ? AuditEvent::Tambah : AuditEvent::Ubah,
                subject: $procurement,
            );

            NotificationScanner::periksaRealisasi(self::segarkan($procurement));
        }

        return $receipt;
    }

    /**
     * Mengisi atau mengoreksi nomor BAST. Mengembalikan true bila nomornya
     * berubah; mengirim nomor yang sama tidak mencatat apa pun.
     *
     * Dipakai BAST digital maupun BAST lama — yang lama tidak punya TTE untuk
     * dicabut, dan pemanggilnya sudah memeriksa `nomorBastDapatDiubah()`.
     */
    public static function isiNomor(Procurement $procurement, User $pelaku, string $nomor): bool
    {
        [$lama, $dicabut] = DB::transaction(function () use ($procurement, $nomor) {
            $procurement = self::kunci($procurement);
            $receipt = $procurement->receipt;
            $lama = $receipt->bast_number;

            if ($lama === $nomor) {
                return [$lama, null];
            }

            $receipt->forceFill(['bast_number' => $nomor])->save();

            return [$lama, self::cabut($receipt, self::ALASAN_NOMOR)];
        });

        if ($dicabut === null) {
            return false;
        }

        AuditLogger::record(
            user: $pelaku,
            activity: ($lama === null
                ? "Mengisi nomor BAST {$nomor} untuk {$procurement->judul()}"
                : "Mengubah nomor BAST {$procurement->judul()} dari {$lama} menjadi {$nomor}")
                .self::kalimatPencabutan($dicabut),
            module: AuditModule::Penerimaan,
            event: AuditEvent::Ubah,
            subject: $procurement,
        );

        NotificationScanner::periksaRealisasi(self::segarkan($procurement));

        return true;
    }

    /**
     * Membubuhkan TTE atas nama pihak yang diwakili peran penanda tangan.
     *
     * TTE pertama membekukan isi BAST; TTE kedua memasukkan stok. Admin tidak
     * mewakili pihak mana pun, sehingga ditolak di sini juga — bukan hanya di
     * gate (ADR 0051).
     *
     * @throws ProcurementTidakSiapException
     */
    public static function tandatangani(Procurement $procurement, User $penandaTangan): ProcurementReceiptSignature
    {
        $pihak = PihakBast::dariPeran($penandaTangan->role) ?? throw ProcurementTidakSiapException::bukanPenandaTangan();

        [$ttd, $lengkap, $nomor] = DB::transaction(function () use ($procurement, $penandaTangan, $pihak) {
            $procurement = self::kunci($procurement);

            if ($procurement->isProsesBelanja()) {
                throw ProcurementTidakSiapException::belumDibelanjakan();
            }

            self::pastikanMenungguPenerimaan($procurement);

            if (! $procurement->hasilPemeriksaanLengkap()) {
                throw ProcurementTidakSiapException::belumDiperiksa();
            }

            $receipt = $procurement->receipt;

            if (blank($receipt->bast_number)) {
                throw ProcurementTidakSiapException::belumBernomor();
            }

            if ($receipt->tteAktif($pihak) !== null) {
                throw ProcurementTidakSiapException::sudahDitandatangani($pihak->label());
            }

            if (! $receipt->sudahDibekukan()) {
                $isi = self::isi($procurement);
                $receipt->forceFill([
                    'content' => $isi,
                    'content_hash' => ProcurementReceipt::sidik($isi),
                ])->save();
            } elseif (! $receipt->isiUtuh()) {
                throw ProcurementTidakSiapException::isiTidakUtuh();
            }

            $ttd = $receipt->signatures()->create([
                'party' => $pihak,
                'user_id' => $penandaTangan->user_id,
                'signer_name' => $penandaTangan->fullname,
                'signer_nip' => $penandaTangan->nip,
                'signer_position' => $penandaTangan->position,
                'content_hash' => $receipt->content_hash,
                'verification_code' => self::kodeVerifikasiBaru(),
                'signed_at' => now(),
            ]);

            $receipt->load('signatures.user');
            $lengkap = collect(PihakBast::cases())->every(fn (PihakBast $p) => $receipt->tteAktif($p) !== null);

            if ($lengkap) {
                // Petugas Persediaan yang mencatat mutasinya, siapa pun yang
                // menandatangani terakhir: penerimaan barang adalah tugas
                // gudang (ADR 0008).
                ProcurementReceiptPoster::posting(
                    $procurement,
                    $receipt,
                    $receipt->tteAktif(PihakBast::Kedua)->user,
                );
            }

            return [$ttd, $lengkap, $receipt->bast_number];
        });

        AuditLogger::record(
            user: $penandaTangan,
            activity: $lengkap
                ? "Membubuhkan TTE sebagai {$pihak->label()} pada BAST {$nomor}; penerimaan {$procurement->judul()} tuntas dan stok bertambah"
                : "Membubuhkan TTE sebagai {$pihak->label()} pada BAST {$nomor} ({$procurement->judul()})",
            module: AuditModule::Penerimaan,
            event: $lengkap ? AuditEvent::Terima : AuditEvent::TandaTangan,
            subject: $procurement,
        );

        if ($lengkap) {
            ProcurementReceiptPoster::setelahPosting($procurement, $penandaTangan);
        } else {
            NotificationScanner::periksaRealisasi(self::segarkan($procurement));
        }

        return $ttd;
    }

    /**
     * Mencabut seluruh TTE aktif dan mengosongkan isi yang dibekukan.
     * Mengembalikan nama penanda tangan yang TTE-nya dicabut.
     *
     * Wajib dipanggil di dalam transaksi pemanggil. Barisnya tidak dihapus:
     * QR pada cetakan lama harus tetap bisa menjelaskan sebabnya.
     *
     * @return list<string>
     */
    public static function cabut(ProcurementReceipt $receipt, string $alasan): array
    {
        $receipt->loadMissing('signatures');
        $aktif = $receipt->signatures->reject(fn (ProcurementReceiptSignature $ttd) => $ttd->dicabut());

        foreach ($aktif as $ttd) {
            $ttd->forceFill(['revoked_at' => now(), 'revoke_reason' => $alasan])->save();
        }

        if ($receipt->content !== null || $receipt->content_hash !== null) {
            $receipt->forceFill(['content' => null, 'content_hash' => null])->save();
        }

        $receipt->unsetRelation('signatures');

        return $aktif->pluck('signer_name')->values()->all();
    }

    /**
     * `cabut()` untuk realisasi yang diubah atau dibatalkan. Hasil
     * pemeriksaannya dipertahankan: fisik barang yang datang tidak berubah
     * karena dokumennya diperbaiki.
     *
     * @return list<string>
     */
    public static function cabutUntukRealisasi(Procurement $procurement, string $alasan): array
    {
        $receipt = $procurement->receipt()->first();

        return $receipt === null ? [] : self::cabut($receipt, $alasan);
    }

    /**
     * Akhiran kalimat audit bila ada TTE yang ikut dicabut.
     *
     * @param  list<string>  $nama
     */
    public static function kalimatPencabutan(array $nama): string
    {
        return $nama === [] ? '' : '; TTE '.implode(' dan ', $nama).' dicabut';
    }

    /**
     * Isi kanonik BAST dari data hidup — yang dibekukan saat TTE pertama.
     *
     * Hanya yang tercetak sebagai isi dokumen. Identitas penanda tangan tidak
     * termasuk: tiap TTE menyalin identitasnya sendiri, dan pihak kedua belum
     * diketahui saat pihak pertama menandatangani.
     *
     * @return array<string, mixed>
     */
    public static function isi(Procurement $procurement): array
    {
        $procurement->loadMissing(['supplier', 'details.item', 'receipt']);
        $receipt = $procurement->receipt;

        return [
            'versi' => 1,
            'nomor' => $receipt?->bast_number,
            'tanggal' => $receipt?->received_at?->toDateString(),
            'catatan' => $receipt?->notes,
            'realisasi' => [
                'id' => $procurement->procurement_id,
                'judul' => $procurement->judul(),
                'tanggal' => $procurement->procurement_date->toDateString(),
            ],
            'penyedia' => $procurement->supplier?->supplier_name,
            'rincian' => $procurement->details
                ->map(fn (ProcurementDetail $detail) => [
                    'id' => $detail->procurement_detail_id,
                    'kode' => $detail->item?->item_code,
                    'nama' => $detail->item?->item_name ?? 'Barang tidak dikenal',
                    'satuan' => $detail->item?->unit?->label(),
                    'dipesan' => $detail->amount,
                    // BAST lama tidak punya hasil pemeriksaan; jumlahnya
                    // hanya ada di `amount_received`.
                    'diterima' => $detail->amount_checked ?? $detail->amount_received,
                    'harga' => $detail->unit_price,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Data cetak BAST: isi yang dibekukan bila sudah ada TTE, data hidup bila
     * belum, ditambah kedua pihak. `$denganTte` false untuk "BAST kosong" —
     * isi dan nama yang sama, tanpa QR, untuk tanda tangan basah.
     *
     * @return array{isi: array<string, mixed>, pihak: array<string, array<string, mixed>>, adaTte: bool}
     */
    public static function untukCetak(Procurement $procurement, bool $denganTte = true): array
    {
        $procurement->loadMissing(['supplier', 'details.item', 'receipt.signatures', 'receipt.receivedBy', 'purchasedBy', 'user']);
        $receipt = $procurement->receipt;

        $isi = $receipt?->sudahDibekukan() ? $receipt->content : self::isi($procurement);

        $pihak = [];
        $adaTte = false;

        foreach (PihakBast::cases() as $p) {
            $ttd = $receipt?->tteAktif($p);
            $orang = $ttd !== null
                ? ['nama' => $ttd->signer_name, 'nip' => $ttd->signer_nip, 'jabatan' => $ttd->signer_position]
                : self::calonPihak($procurement, $p);

            $tte = null;

            if ($ttd !== null && $denganTte) {
                $adaTte = true;
                // Waktu TTE tidak dicetak (ADR 0052); halaman verifikasi
                // yang dibuka QR menampilkannya.
                $tte = [
                    'qr' => KodeQr::dataUri(self::urlVerifikasi($ttd)),
                ];
            }

            $pihak[$p->value] = $orang + ['sebutan' => $p->jabatanTtd(), 'tte' => $tte];
        }

        return ['isi' => $isi, 'pihak' => $pihak, 'adaTte' => $adaTte];
    }

    /**
     * Ringkasan kemajuan BAST untuk chip di halaman detail dan daftar.
     */
    public static function tahap(Procurement $procurement): string
    {
        if ($procurement->isDiterima()) {
            return 'Lengkap';
        }

        if (! $procurement->hasilPemeriksaanLengkap()) {
            return 'Menunggu pemeriksaan';
        }

        if (blank($procurement->receipt->bast_number)) {
            return 'Menunggu nomor';
        }

        return 'TTE '.$procurement->receipt->jumlahTteAktif().' dari 2';
    }

    /**
     * Alamat halaman verifikasi yang ditanam di QR.
     *
     * Dibangun dari APP_URL, bukan dari host permintaan: BAST yang dicetak
     * lewat `localhost` harus tetap terbaca dari ponsel. Karena itu APP_URL di
     * server wajib berupa alamat yang bisa dijangkau pemindainya.
     */
    public static function urlVerifikasi(ProcurementReceiptSignature $ttd): string
    {
        return rtrim((string) config('app.url'), '/')
            .route('bast.verify', ['kode' => $ttd->verification_code], false);
    }

    /**
     * Calon penanda tangan sebelum TTE-nya ada, untuk pratinjau.
     *
     * Pihak Pertama: yang menandai realisasi dibelanjakan, cadangannya
     * pembuatnya (ADR 0042). Pihak Kedua: Pengelola Gudang yang mencatat hasil
     * pemeriksaan. Admin yang mencatat tidak dicetak, karena Admin tidak
     * pernah menandatangani (ADR 0051) — kecuali pada BAST lama, yang selalu
     * mencetak pencatatnya.
     *
     * @return array{nama: ?string, nip: ?string, jabatan: ?string}
     */
    private static function calonPihak(Procurement $procurement, PihakBast $pihak): array
    {
        $user = match ($pihak) {
            PihakBast::Pertama => $procurement->purchasedBy ?? $procurement->user,
            PihakBast::Kedua => $procurement->receipt?->receivedBy,
        };

        if ($pihak === PihakBast::Kedua && $user !== null
            && ! $procurement->bastLama() && ! $user->hasRole(UserRole::PengelolaGudang)) {
            $user = null;
        }

        return ['nama' => $user?->fullname, 'nip' => $user?->nip, 'jabatan' => $user?->position];
    }

    private static function kodeVerifikasiBaru(): string
    {
        do {
            $kode = Str::random(40);
        } while (ProcurementReceiptSignature::query()->where('verification_code', $kode)->exists());

        return $kode;
    }

    /**
     * Mengunci baris realisasi untuk seluruh aksi BAST, lalu memuat ulang
     * relasinya di dalam kunci itu.
     */
    private static function kunci(Procurement $procurement): Procurement
    {
        return Procurement::query()
            ->lockForUpdate()
            ->with(['supplier', 'details.item', 'receipt.signatures'])
            ->findOrFail($procurement->procurement_id);
    }

    /**
     * @throws ProcurementTidakSiapException
     */
    private static function pastikanMenungguPenerimaan(Procurement $procurement): void
    {
        if ($procurement->isProsesBelanja()) {
            throw ProcurementTidakSiapException::belumDibelanjakan();
        }

        if (! $procurement->isMenungguPenerimaan()) {
            throw ProcurementTidakSiapException::bukanMenungguPenerimaan();
        }
    }

    private static function segarkan(Procurement $procurement): Procurement
    {
        return $procurement->fresh(['supplier', 'details', 'receipt.signatures']);
    }

    /**
     * Tanggal isi BAST (string Y-m-d di JSON) sebagai Carbon, atau null.
     */
    public static function tanggal(?string $nilai): ?Carbon
    {
        return $nilai === null ? null : Carbon::parse($nilai);
    }
}
