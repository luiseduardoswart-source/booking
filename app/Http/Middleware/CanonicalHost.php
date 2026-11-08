<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One address for the site: visitors who arrive on a different hostname than
 * the one in APP_URL are permanently redirected there. This way the owner
 * chooses whether the site lives on www or on the bare domain, without two
 * versions existing side by side (duplicate content for search engines, and
 * sessions and signed links that differ per hostname).
 *
 * Note: the server's certificate must cover the hostname from APP_URL,
 * otherwise you send everyone to a warning.
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        // Live only: locally the site often runs on a different hostname than
        // APP_URL (herd.test, 127.0.0.1, a tunnel) and redirecting would only
        // be annoying. Tests run in the 'testing' environment.
        if (! app()->environment('production')) {
            return $next($request);
        }

        // Only reads may be redirected. Redirecting a POST means the data
        // disappears on the way, think of webhooks from payment
        // providers, which ignore a 301.
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $canonical = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($canonical) || $canonical === '' || strcasecmp($canonical, $request->getHost()) === 0) {
            return $next($request);
        }

        $target = rtrim((string) config('app.url'), '/').'/'.ltrim($request->getRequestUri(), '/');

        return redirect()->away($target, 301);
    }
}
