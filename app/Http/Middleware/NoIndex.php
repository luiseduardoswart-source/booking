<?php

namespace App\Http\Middleware;

use App\Support\LocalizedUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the pages meant for search results may end up there: the home page,
 * contact, accommodations and pages (LocalizedUrl::INDEXABLE). Everything
 * else on the site (booking, paying, My bookings, ID check, documents) tells
 * search engines to leave it out, also a PDF, through the X-Robots-Tag header.
 * A new page is private until it is added to the list: never the other way round.
 */
class NoIndex
{
    /** Read by search engines themselves: no header there. */
    private const FOR_SEARCH_ENGINES = ['sitemap', 'robots'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! LocalizedUrl::isIndexable($request) && ! $request->routeIs(...self::FOR_SEARCH_ENGINES)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
