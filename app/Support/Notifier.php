<?php

namespace App\Support;

use App\Enums\NotificationLevel;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Penulis notifikasi ke tabel `user_notifications`.
 *
 * Mengikuti prinsip yang sama dengan AuditLogger: kegagalan sengaja tidak
 * dilempar ke atas. Notifikasi adalah lapisan bantu — gagal menulisnya tidak
 * boleh menggagalkan mutasi stok atau posting opname yang sedang berjalan.
 *
 * Dua primitif saja:
 *
 * - `terapkanKeadaan()` untuk hal yang *sedang* benar. Dipanggil dengan sebuah
 *   boolean; bila benar notifikasinya dipastikan ada, bila salah barisnya
 *   dihapus. Karena itu ia idempoten: pemanggilan kedua, kesepuluh, dan
 *   keseratus menghasilkan keadaan akhir yang sama.
 * - `catatPeristiwa()` untuk fakta historis, yang tidak punya kondisi pulih
 *   dan karenanya dibuang oleh `bersihkan()` setelah berumur tujuh hari.
 *   Setiap pemanggilannya adalah kejadian baru (ADR 0037).
 *
 * Idempotensi inilah yang membuat pemeriksaan reaktif (setelah mutasi) dan
 * pemeriksaan terjadwal (perintah `notifikasi:periksa`) boleh hidup
 * berdampingan tanpa saling menduplikasi.
 */
class Notifier
{
    /**
     * Umur peristiwa sebelum dibuang, dibaca atau belum. Keadaan tidak ikut:
     * ia bertahan selama kondisinya bertahan, berapa pun umurnya (ADR 0036).
     */
    private const HARI_UMUR_PERISTIWA = 7;

    /**
     * Pastikan sebuah keadaan tercermin di lonceng penerima.
     *
     * @param  iterable<int, User>  $penerima
     */
    public static function terapkanKeadaan(
        bool $berlaku,
        iterable $penerima,
        NotificationType $type,
        string $dedupKey,
        string $title,
        string $body,
        ?string $actionUrl = null,
        ?Model $subject = null,
    ): void {
        if (! $berlaku) {
            self::selesaikan($dedupKey);

            return;
        }

        self::tulis($penerima, $type, $dedupKey, $title, $body, $actionUrl, $subject, kejadianBaru: false);
    }

    /**
     * Catat peristiwa yang sudah terjadi.
     *
     * Berbeda dari keadaan, setiap pemanggilan di sini adalah kejadian yang
     * sungguh terjadi lagi — peristiwa hanya dicatat dari tindakan pengguna,
     * tidak pernah dari pemeriksaan terjadwal. Karena itu baris yang sudah ada
     * dikembalikan ke lonceng sebagai kabar baru (ADR 0037).
     *
     * @param  iterable<int, User>  $penerima
     */
    public static function catatPeristiwa(
        iterable $penerima,
        NotificationType $type,
        string $dedupKey,
        string $title,
        string $body,
        ?string $actionUrl = null,
        ?Model $subject = null,
    ): void {
        self::tulis($penerima, $type, $dedupKey, $title, $body, $actionUrl, $subject, kejadianBaru: true);
    }

    /**
     * Hapus sebuah keadaan untuk seluruh penerimanya sekaligus.
     *
     * Keadaan berlaku bagi gudang secara keseluruhan, bukan bagi orang per
     * orang: begitu stok terisi, ia pulih untuk semua yang diberi tahu.
     *
     * Barisnya dihapus, bukan ditandai selesai (ADR 0036). Keadaan yang pulih
     * tidak menyisakan apa pun yang perlu ditindak, dan riwayat berapa kali
     * sebuah barang habis sudah dipegang `stock_mutations`. Bila keadaannya
     * kambuh, ia lahir sebagai baris baru yang belum dibaca — itulah yang
     * memang diinginkan: kambuh bukan kabar lama.
     */
    public static function selesaikan(string $dedupKey): void
    {
        try {
            Notification::query()
                ->where('dedup_key', $dedupKey)
                ->delete();
        } catch (Throwable $e) {
            self::catatKegagalan('menutup notifikasi', $dedupKey, $e);
        }
    }

    public static function tandaiDibaca(Notification $notification): void
    {
        if ($notification->sudahDibaca()) {
            return;
        }

        $notification->forceFill(['read_at' => now()])->save();
    }

    public static function tandaiSemuaDibaca(User $user): int
    {
        return Notification::query()
            ->untuk($user)
            ->belumDibaca()
            ->update(['read_at' => now()]);
    }

    /**
     * Pengguna aktif dengan salah satu peran yang disebut.
     *
     * @return EloquentCollection<int, User>
     */
    public static function penerimaBerperan(UserRole ...$peran): EloquentCollection
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', array_map(fn (UserRole $role) => $role->value, $peran))
            ->get();
    }

    /**
     * Buang seluruh notifikasi yang menunjuk sebuah subjek.
     *
     * Dipakai ketika subjeknya benar-benar dihapus: notifikasi yang tersisa
     * hanya akan mengantar penggunanya ke halaman yang sudah tidak ada.
     */
    public static function hapusUntukSubjek(Model $subject): int
    {
        try {
            return Notification::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->delete();
        } catch (Throwable $e) {
            self::catatKegagalan('menghapus notifikasi subjek', (string) $subject->getKey(), $e);

            return 0;
        }
    }

    /**
     * Buang peristiwa yang sudah berumur lebih dari tujuh hari, dibaca atau
     * belum, supaya tabel tidak tumbuh selamanya.
     *
     * Yang belum dibaca pun ikut dibuang: kabar yang dibiarkan seminggu sudah
     * kehilangan kesegeraannya, dan dokumen yang dikabarkannya tetap dapat
     * dibuka dari modulnya sendiri. Keadaan tidak pernah disentuh di sini —
     * ia hanya hilang saat kondisinya pulih.
     */
    public static function bersihkan(): int
    {
        return Notification::query()
            ->peristiwa()
            ->where('created_at', '<', now()->subDays(self::HARI_UMUR_PERISTIWA))
            ->delete();
    }

    /**
     * Kembalikan ke lonceng keadaan yang masih berlangsung tetapi sudah lama
     * dibaca, menurut `NotificationLevel::hariIngatkanUlang()` (ADR 0037).
     *
     * Tanpa ini, keadaan yang sekali dibaca diam sampai kondisinya pulih:
     * "stok habis" yang dibaca hari Senin tidak pernah menagih lagi. Dipanggil
     * pemeriksaan terjadwal saja, sejalan dengan hal berbasis waktu lainnya
     * (ADR 0014).
     *
     * Ambangnya dihitung per hari kalender, bukan per 24 jam, supaya
     * pemeriksaan pagi menagih semua yang dibaca kemarin — dan supaya
     * menjalankan perintahnya dua kali dalam sehari tidak menagih dua kali.
     * Peristiwa tidak pernah diingatkan ulang.
     */
    public static function ingatkanUlang(): int
    {
        $jumlah = 0;

        foreach (NotificationLevel::cases() as $level) {
            $hari = $level->hariIngatkanUlang();

            if ($hari === null) {
                continue;
            }

            $jumlah += Notification::query()
                ->keadaan()
                ->where('level', $level)
                ->where('read_at', '<', now()->startOfDay()->subDays($hari - 1))
                ->update(['read_at' => null]);
        }

        return $jumlah;
    }

    /**
     * @param  iterable<int, User>  $penerima
     */
    private static function tulis(
        iterable $penerima,
        NotificationType $type,
        string $dedupKey,
        string $title,
        string $body,
        ?string $actionUrl,
        ?Model $subject,
        bool $kejadianBaru,
    ): void {
        foreach ($penerima as $user) {
            try {
                self::tulisSatu($user, $type, $dedupKey, $title, $body, $actionUrl, $subject, $kejadianBaru);
            } catch (Throwable $e) {
                self::catatKegagalan('menulis notifikasi', $dedupKey, $e);
            }
        }
    }

    private static function tulisSatu(
        User $user,
        NotificationType $type,
        string $dedupKey,
        string $title,
        string $body,
        ?string $actionUrl,
        ?Model $subject,
        bool $kejadianBaru,
    ): void {
        $notification = Notification::query()->firstOrNew([
            'user_id' => $user->user_id,
            'dedup_key' => $dedupKey,
        ]);

        $notification->fill([
            'type' => $type,
            'level' => $type->level(),
            'title' => $title,
            'body' => $body,
            'action_url' => $actionUrl,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);

        // Peristiwa yang terulang pada subjek yang sama — realisasi yang
        // ditolak lagi setelah diperbaiki — memakai baris lama, tetapi harus
        // terdengar seperti kabar baru, dan umur tujuh harinya dihitung ulang
        // dari kejadian terakhir. Keadaan tidak boleh ikut: pemeriksaan
        // terjadwal menulisnya ulang setiap pagi.
        if ($kejadianBaru && $notification->exists) {
            $notification->read_at = null;
            $notification->created_at = now();
        }

        $notification->save();
    }

    private static function catatKegagalan(string $aksi, string $dedupKey, Throwable $e): void
    {
        Log::error("Gagal {$aksi}.", [
            'dedup_key' => $dedupKey,
            'exception' => $e->getMessage(),
        ]);
    }
}
