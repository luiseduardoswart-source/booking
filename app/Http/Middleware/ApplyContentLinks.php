<?php

namespace App\Http\Middleware;

use App\Support\ContentLink;
use Closure;
use Illuminate\Http\Request;

/**
 * Fills the linked elements with page content in the HTML response. Server-side,
 * so the content is also correct without JavaScript and for search engines. If
 * there are no links, nothing is parsed.
 */
class ApplyContentLinks
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $type = (string) $response->headers->get('Content-Type');
        if ($type !== '' && ! str_contains($type, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return $response;
        }

        $new = ContentLink::apply($content);
        if ($new !== $content) {
            $response->setContent($new);
        }

        return $response;
    }
}
