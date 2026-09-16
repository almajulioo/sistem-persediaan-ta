<?php

namespace App\Support;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Penulis jejak aktivitas ke tabel `audit_logs`.
 *
 * Kegagalan pencatatan sengaja tidak dilempar ke atas supaya tidak
 * menggagalkan aksi pengguna; kegagalan tetap masuk ke log aplikasi.
 */
class AuditLogger
{
    /**
     * Catat satu aktivitas pengguna.
     *
     * `$module` dan `$event` wajib, tanpa nilai bawaan: kolom terstruktur
     * itulah yang membuat log dapat disaring, dan nilai bawaan hanya akan
     * menjamin terjadinya pembusukan data diam-diam. Lihat ADR 0034.
     *
     * Seluruh argumen bersifat *named* supaya penambahan parameter kelak tidak
     * merusak pemanggil yang ada, menyamai `StockLedger::record()`.
     *
     * @param  string  $activity  Kalimat yang dibaca manusia, memuat rincian
     *                            yang tidak mungkin ditampung enum: kode
     *                            barang, jumlah, alasan, nomor dokumen.
     * @param  Model|null  $subject  Dokumen yang dikenai aksi, supaya baris log
     *                               dapat ditelusuri balik ke sumbernya.
     */
    public static function record(
        User $user,
        string $activity,
        AuditModule $module,
        AuditEvent $event,
        ?Model $subject = null,
    ): void {
        try {
            AuditLog::create([
                'user_id' => $user->user_id,
                'activity' => $activity,
                'module' => $module,
                'event' => $event,
                // Cuplikan, bukan turunan relasi: peran pengguna dapat berubah,
                // sedangkan jejak audit harus tetap menyatakan kapasitas apa
                // yang dipakainya saat aksi ini dilakukan.
                'actor_role' => $user->role,
                'ip_address' => self::alamatIp(),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'timestamp' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Gagal menulis audit log.', [
                'user_id' => $user->user_id,
                'activity' => $activity,
                'module' => $module->value,
                'event' => $event->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Alamat IP pemanggil, atau null bila aksi berasal dari perintah konsol
     * seperti `notifikasi:periksa` yang memang tidak punya asal jaringan.
     *
     * `runningInConsole()` juga bernilai true saat rangkaian uji dijalankan
     * lewat artisan, padahal uji fitur mensimulasikan permintaan HTTP lengkap
     * dengan alamat asalnya. Tanpa pengecualian itu kolom ini tidak akan
     * pernah terisi di lingkungan uji, sehingga perilakunya tidak dapat diuji
     * sama sekali.
     */
    private static function alamatIp(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        return request()->ip();
    }
}
