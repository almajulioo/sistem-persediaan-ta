<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Halaman formulir masuk.
     */
    public function create(): View
    {
        return view('pages.auth.login', ['title' => 'Masuk']);
    }

    /**
     * Proses masuk dan buat sesi baru.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Cegah session fixation: identifier sesi diganti setelah autentikasi berhasil.
        $request->session()->regenerate();

        $user = $request->user();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        AuditLogger::record(
            user: $user,
            activity: 'Masuk ke sistem',
            module: AuditModule::Autentikasi,
            event: AuditEvent::Masuk,
        );

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Proses keluar dan hancurkan sesi.
     */
    public function destroy(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            AuditLogger::record(
                user: $user,
                activity: 'Keluar dari sistem',
                module: AuditModule::Autentikasi,
                event: AuditEvent::Keluar,
            );
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Anda telah keluar dari sistem.');
    }
}
