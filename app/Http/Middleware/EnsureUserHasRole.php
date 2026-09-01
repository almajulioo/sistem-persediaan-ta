<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi rute berdasarkan peran, contoh: ->middleware('role:admin,pengelola_gudang')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);
        abort_if(! $user->hasRole(...$roles), 403, 'Anda tidak memiliki hak akses untuk halaman ini.');

        return $next($request);
    }
}
