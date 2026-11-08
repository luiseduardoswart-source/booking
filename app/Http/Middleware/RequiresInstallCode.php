<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only lets through those who entered the install code (see
 * App\Support\InstallCode). Without this, anyone who finds /install
 * could make themselves an administrator as long as no account exists yet.
 */
class RequiresInstallCode
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('install_code_ok') === true) {
            return $next($request);
        }

        return redirect()->route('install.code');
    }
}
