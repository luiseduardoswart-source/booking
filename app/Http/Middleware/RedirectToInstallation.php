<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * If the tables do not exist yet, there is nothing to show: send the visitor
 * to the installation wizard instead of a database error.
 *
 * Deliberately NOT based on "does a user exist": a running site where the last
 * admin disappears would suddenly send all its visitors to an installation
 * screen. EnsureNotInstalled takes care of closing the wizard itself once
 * an account exists.
 */
class RedirectToInstallation
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip the wizard itself, of course, otherwise you get a loop.
        if ($request->is('install', 'install/*')) {
            return $next($request);
        }

        if (! EnsureNotInstalled::databaseIsSetUp()) {
            return redirect()->route('install.database');
        }

        return $next($request);
    }
}
