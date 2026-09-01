<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tamu yang menyentuh rute terlindungi diarahkan ke halaman masuk.
        $middleware->redirectGuestsTo(fn () => route('login'));

        // Pengguna yang sudah masuk tidak perlu melihat halaman masuk lagi.
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        $middleware->web(append: [
            // Sesi lain otomatis gugur ketika kata sandi diubah.
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
