<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Satu-satunya tempat foto profil disimpan, disajikan, dan dibuang.
 *
 * Diskinya privat, sama dengan berkas pengadaan (ADR 0018): foto wajah
 * pegawai tidak boleh terunduh oleh siapa pun yang menebak URL-nya, dan
 * dengan begitu server tidak memerlukan `storage:link`. Foto disajikan lewat
 * rute terautentikasi. Lihat ADR 0038.
 */
class AvatarStorage
{
    /** Disk privat bawaan Laravel: storage/app/private. */
    public const DISK = 'local';

    private const FOLDER = 'avatars';

    /**
     * Berapa lama peramban boleh menyimpan foto, dalam detik (7 hari).
     *
     * Aman walau lama, karena URL-nya ikut berganti tiap unggahan baru —
     * lihat `User::urlFoto()`. Tanpa cache, foto di header ikut diminta ulang
     * pada setiap halaman yang dibuka.
     */
    private const CACHE_DETIK = 604800;

    /**
     * Simpan foto unggahan dengan nama acak, mengembalikan path relatif disk.
     *
     * Nama asli tidak dipakai sama sekali — tidak ada yang perlu dipulihkan
     * dari nama berkas sebuah foto profil, dan nama dari klien adalah celah
     * path traversal (lihat `DocumentStorage::simpan()`).
     */
    public static function simpan(UploadedFile $file): string
    {
        $ekstensi = strtolower($file->extension() ?: 'jpg');

        return (string) $file->storeAs(
            self::FOLDER,
            Str::uuid()->toString().'.'.$ekstensi,
            ['disk' => self::DISK],
        );
    }

    /**
     * Tampilkan foto di peramban. `nosniff` mencegah peramban menebak-nebak
     * jenis isinya menjadi sesuatu yang dapat dijalankan.
     */
    public static function tampilkan(User $user): StreamedResponse
    {
        return Storage::disk(self::DISK)->response($user->avatar_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age='.self::CACHE_DETIK,
        ]);
    }

    public static function hapus(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Apakah berkas fotonya masih ada di disk. Baris dan berkas dapat
     * terpisah — misalnya saat pemulihan cadangan basis data tanpa storage.
     */
    public static function ada(User $user): bool
    {
        return $user->punyaFoto() && Storage::disk(self::DISK)->exists($user->avatar_path);
    }
}
