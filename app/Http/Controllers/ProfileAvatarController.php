<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Http\Requests\Profile\UpdateProfileAvatarRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\AvatarStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Foto profil: unggah dan hapus milik sendiri, serta menyajikan foto siapa pun
 * kepada pengguna yang sudah masuk.
 *
 * Menyajikan tidak dibatasi pada pemiliknya karena foto tampil di tempat
 * orang lain melihatnya — daftar pengguna milik Admin, misalnya. Yang dijaga
 * hanya bahwa penontonnya sudah masuk; itu sebabnya fotonya di disk privat
 * (ADR 0038).
 */
class ProfileAvatarController extends Controller
{
    public function show(User $user): StreamedResponse
    {
        abort_unless(AvatarStorage::ada($user), 404);

        return AvatarStorage::tampilkan($user);
    }

    public function update(UpdateProfileAvatarRequest $request): RedirectResponse
    {
        $user = $request->user();
        $lama = $user->avatar_path;
        $baru = AvatarStorage::simpan($request->file('avatar'));

        // Berkas ditulis lebih dulu daripada barisnya. Bila penyimpanan baris
        // gagal, berkas baru dibuang supaya tidak ada foto yatim di disk.
        try {
            $user->forceFill(['avatar_path' => $baru])->save();
        } catch (Throwable $e) {
            AvatarStorage::hapus($baru);

            throw $e;
        }

        // Berkas lama baru dibuang setelah baris menunjuk berkas baru — urutan
        // sebaliknya menyisakan baris yang menunjuk berkas yang sudah tiada.
        AvatarStorage::hapus($lama);

        AuditLogger::record(
            user: $user,
            activity: $lama ? 'Mengganti foto profil' : 'Mengunggah foto profil',
            module: AuditModule::Profil,
            event: AuditEvent::Unggah,
            subject: $user,
        );

        return back()->with('status', 'Foto profil berhasil diperbarui.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->punyaFoto()) {
            return back()->with('error', 'Anda belum memiliki foto profil.');
        }

        $path = $user->avatar_path;
        $user->forceFill(['avatar_path' => null])->save();
        AvatarStorage::hapus($path);

        AuditLogger::record(
            user: $user,
            activity: 'Menghapus foto profil',
            module: AuditModule::Profil,
            event: AuditEvent::Hapus,
            subject: $user,
        );

        return back()->with('status', 'Foto profil dihapus.');
    }
}
