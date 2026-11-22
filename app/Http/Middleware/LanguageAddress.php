<?php

namespace App\Http\Middleware;

use App\Support\LocalizedUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the pages search engines index: the address has to be that of the
 * language the page is shown in (SetLocale decided that). Otherwise go there:
 *
 * - permanently (301) when the address itself named another language, or an
 *   old or wrong address of it (/casa-frente-al-mar, /es/beach-house, /en/...);
 * - permanently as well from the address of the one accommodation the main
 *   domain shows: the main domain is its address (LocalizedUrl::homeProperty);
 * - temporarily (302) when it is the visitor's own choice of language, so
 *   search engines, which bring none, keep the default address.
 */
class LanguageAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $target = LocalizedUrl::inLanguage($request->fullUrl(), app()->getLocale());

        if (self::path($target) === self::path($request->fullUrl())) {
            return $next($request);
        }

        // Permanent too when the accommodation's own address has become the main domain.
        $permanent = $request->attributes->get(SetLocale::FROM_ADDRESS) || LocalizedUrl::isHomePropertyAddress($request);

        return redirect($target, $permanent ? 301 : 302);
    }

    private static function path(string $address): string
    {
        return '/'.trim((string) parse_url($address, PHP_URL_PATH), '/');
    }
}
