<?php

namespace App\Support;

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
 *   boolean; bila benar notifikasinya dipastikan ada, bila salah notifikasinya
 *   ditutup. Karena itu ia idempoten: pemanggilan kedua, kesepuluh, dan
 *   keseratus menghasilkan keadaan akhir yang sama.
 * - `catatPeristiwa()` untuk fakta historis, yang tidak pernah ditutup sendiri.
 *
 * Idempotensi inilah yang membuat pemeriksaan reaktif (setelah mutasi) dan
 * pemeriksaan terjadwal (perintah `notifikasi:periksa`) boleh hidup
 * berdampingan tanpa saling menduplikasi.
 */
class Notifier
{
    /**
     * Berapa lama notifikasi yang sudah selesai dan sudah dibaca disimpan
     * sebelum dibersihkan.
     */
    private const HARI_SIMPAN = 30;

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

        self::tulis($penerima, $type, $dedupKey, $title, $body, $actionUrl, $subject);
    }

    /**
     * Catat peristiwa yang sudah terjadi.
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
        self::tulis($penerima, $type, $dedupKey, $title, $body, $actionUrl, $subject);
    }

    /**
     * Tutup sebuah keadaan untuk seluruh penerimanya sekaligus.
     *
     * Keadaan berlaku bagi gudang secara keseluruhan, bukan bagi orang per
     * orang: begitu stok terisi, ia pulih untuk semua yang diberi tahu.
     */
    public static function selesaikan(string $dedupKey): void
    {
        try {
            Notification::query()
                ->where('dedup_key', $dedupKey)
                ->aktif()
                ->update(['resolved_at' => now()]);
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
     * Buang notifikasi lama yang sudah selesai dan sudah dibaca supaya tabel
     * tidak tumbuh selamanya. Yang masih aktif atau belum dibaca tidak
     * pernah disentuh.
     */
    public static function bersihkan(): int
    {
        return Notification::query()
            ->whereNotNull('resolved_at')
            ->whereNotNull('read_at')
            ->where('resolved_at', '<', now()->subDays(self::HARI_SIMPAN))
            ->delete();
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
    ): void {
        foreach ($penerima as $user) {
            try {
                self::tulisSatu($user, $type, $dedupKey, $title, $body, $actionUrl, $subject);
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
    ): void {
        $notification = Notification::query()->firstOrNew([
            'user_id' => $user->user_id,
            'dedup_key' => $dedupKey,
        ]);

        // Keadaan yang pernah pulih lalu muncul lagi. Barisnya dipakai ulang
        // supaya barang yang stoknya bergoyang di sekitar batas minimum tidak
        // menumpuk puluhan baris, tetapi jamnya disetel ulang karena yang
        // ditampilkan adalah episode yang sedang berlangsung, bukan yang dulu.
        $kambuh = $notification->exists && $notification->resolved_at !== null;

        $notification->fill([
            'type' => $type,
            'level' => $type->level(),
            'title' => $title,
            'body' => $body,
            'action_url' => $actionUrl,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);

        if (! $notification->exists || $kambuh) {
            $notification->read_at = null;
            $notification->resolved_at = null;
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
