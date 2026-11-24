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
 * The same for https: with an https address in APP_URL, a visit over plain
 * http goes to https, so logins, payment links and the session never cross
 * the network unencrypted. A proxy in front of the server that already
 * speaks https with the visitor says so in X-Forwarded-Proto; that header is
 * only used to avoid an endless redirect, never to trust anything.
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

        if (! is_string($canonical) || $canonical === '') {
            return $next($request);
        }

        $rightHost = strcasecmp($canonical, $request->getHost()) === 0;
        $wantsHttps = strcasecmp((string) parse_url((string) config('app.url'), PHP_URL_SCHEME), 'https') === 0;

        if ($rightHost && (! $wantsHttps || $this->secure($request))) {
            return $next($request);
        }

        $target = rtrim((string) config('app.url'), '/').'/'.ltrim($request->getRequestUri(), '/');

        return redirect()->away($target, 301);
    }

    /** Is the visitor on https, here or at a proxy in front of the server? */
    private function secure(Request $request): bool
    {
        $forwarded = strtolower(trim(explode(',', (string) $request->headers->get('X-Forwarded-Proto'))[0]));

        return $request->isSecure() || $forwarded === 'https';
    }
}
