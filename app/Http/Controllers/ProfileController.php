<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Http\Requests\Profile\UpdateProfileEmailRequest;
use App\Http\Requests\Profile\UpdateProfilePasswordRequest;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Profil milik pengguna yang sedang masuk.
 *
 * Tanpa gate peran, sama seperti notifikasi: setiap akun berhak atas
 * profilnya sendiri, dan tidak ada parameter rute yang menunjuk akun lain —
 * semuanya bekerja pada `$request->user()`.
 *
 * Swalayannya sengaja terbatas pada kata sandi, email, dan foto. Nama
 * lengkap, nama pengguna, peran, unit, dan status tetap wewenang Admin
 * karena menentukan identitas resmi dan otorisasi; halaman ini hanya
 * menampilkannya. Lihat ADR 0038.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('pages.profile.index', [
            'title' => 'Profil Saya',
            'user' => $request->user(),
        ]);
    }

    public function updateEmail(UpdateProfileEmailRequest $request): RedirectResponse
    {
        $user = $request->user();
        $lama = $user->email;
        $baru = $request->validated('email');

        if ($baru === $lama) {
            return back()->with('status', 'Email tidak berubah.');
        }

        $user->forceFill(['email' => $baru])->save();

        // Email lama ikut dicatat: ia pengenal masuk, dan tanpa nilai lamanya
        // pemeriksa tidak dapat menjawab "akun mana yang dulu memakai email ini".
        AuditLogger::record(
            user: $user,
            activity: "Mengubah email akun sendiri dari {$lama} menjadi {$baru}",
            module: AuditModule::Profil,
            event: AuditEvent::Ubah,
            subject: $user,
        );

        return back()->with('status', 'Email berhasil diperbarui.');
    }

    /**
     * Ganti kata sandi sendiri.
     *
     * Sesi di perangkat lain keluar dengan sendirinya: middleware
     * `AuthenticateSession` membandingkan hash kata sandi yang tersimpan di
     * tiap sesi dengan hash terkini, lalu menyimpan hash baru ke sesi *ini*
     * setelah request selesai — sehingga hanya sesi ini yang bertahan.
     */
    public function updatePassword(UpdateProfilePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill(['password' => $request->validated('password')])->save();

        // ID sesi diganti setelah kredensial berubah, supaya ID lama yang
        // mungkin sempat bocor tidak ikut menjadi sesi yang sah.
        $request->session()->regenerate();

        AuditLogger::record(
            user: $user,
            activity: 'Mengganti kata sandi akun sendiri',
            module: AuditModule::Profil,
            event: AuditEvent::Ubah,
            subject: $user,
        );

        return back()->with('status', 'Kata sandi berhasil diganti.');
    }
}
