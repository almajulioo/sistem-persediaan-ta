<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
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
    public static function record(User $user, string $activity): void
    {
        try {
            AuditLog::create([
                'user_id' => $user->user_id,
                'activity' => $activity,
                'timestamp' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Gagal menulis audit log.', [
                'user_id' => $user->user_id,
                'activity' => $activity,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
