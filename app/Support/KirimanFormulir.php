<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Formulir pembuat dokumen yang terkirim dua kali — tombol Kembali peramban
 * atau klik ganda — tidak membuat dokumen kedua (ADR 0045, ADR 0046).
 *
 * Token dari `x-form.token-kiriman` dicatat bersama dokumennya di
 * `form_submissions`, dalam transaksi yang sama. Dua kiriman bersamaan lolos
 * pemeriksaan awal berdua; yang kalah ditolak kunci primer token.
 */
class KirimanFormulir
{
    /**
     * @template T of Model
     *
     * @param  Closure(): T  $buat
     * @return array{0: T|null, 1: bool} dokumen, dan apakah ini kiriman ulang
     *                                   (dokumennya null bila sudah dihapus)
     */
    public static function sekali(Request $request, Closure $buat): array
    {
        $token = (string) $request->input('submission_token');

        if (! Str::isUuid($token)) {
            throw ValidationException::withMessages([
                'submission_token' => 'Formulir sudah kedaluwarsa. Muat ulang halaman, lalu kirim kembali.',
            ]);
        }

        if ($lama = self::cari($token)) {
            return [self::dokumen($lama), true];
        }

        try {
            $dokumen = DB::transaction(function () use ($token, $buat) {
                $dokumen = $buat();

                DB::table('form_submissions')->insert([
                    'token' => $token,
                    'subject_type' => $dokumen->getMorphClass(),
                    'subject_id' => $dokumen->getKey(),
                    'created_at' => now(),
                ]);

                return $dokumen;
            });
        } catch (UniqueConstraintViolationException $e) {
            return [self::dokumen(self::cari($token) ?? throw $e), true];
        }

        return [$dokumen, false];
    }

    private static function cari(string $token): ?object
    {
        return DB::table('form_submissions')->where('token', $token)->first();
    }

    private static function dokumen(object $baris): ?Model
    {
        $kelas = Relation::getMorphedModel($baris->subject_type) ?? $baris->subject_type;

        return $kelas::find($baris->subject_id);
    }
}
