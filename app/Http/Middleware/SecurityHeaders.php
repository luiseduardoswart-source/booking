<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response.
 *
 * - The browser uses the content type the server gives (no guessing that an
 *   upload is a web page), and other sites only learn this site's domain
 *   from links, not the full address with its parameters.
 * - Pages where someone is logged in or makes choices (the admin, My
 *   bookings, the installation, an invitation) cannot be shown inside
 *   another site, so nobody can lure the owner into clicking a hidden
 *   button there. The rest of the site stays embeddable: the share modal
 *   offers an embed code for an accommodation.
 * - On https, the browser keeps using https.
 */
class SecurityHeaders
{
    /** Paths that are never shown inside another site. */
    private const NOT_EMBEDDABLE = ['admin', 'admin/*', 'admin-media/*', 'admin-menu/*', 'my-bookings', 'my-bookings/*', 'install', 'install/*', 'invitation/*', 'oauth/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);

        // A narrow content policy: no <base> tag pointing links and forms to
        // another site, no <object>/<embed> from another site (the media
        // manager shows the site's own PDFs that way), and the pages above
        // not framed by another site. Scripts are left free on purpose: the
        // owner's own code (statistics, chat) and the admin's scripts must run.
        $policy = "base-uri 'self'; object-src 'self'";

        if ($request->is(...self::NOT_EMBEDDABLE)) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
            $policy .= "; frame-ancestors 'self'";
        }

        $headers->set('Content-Security-Policy', $policy, false);

        if ($request->isSecure() && app()->environment('production')) {
            $headers->set('Strict-Transport-Security', 'max-age=15552000', false);
        }

        return $response;
    }
}
