<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * The regular session middleware, but a data URL (route name "api.*",
 * such as the price calculation and the search suggestions) does not count as the
 * "previous page". The site fetches those URLs with fetch() without an AJAX header; otherwise
 * a language or currency switch would send the visitor to raw JSON afterwards.
 */
class StartSessionWithoutDataAddresses extends StartSession
{
    protected function storeCurrentUrl(Request $request, $session)
    {
        if ($request->route()?->named('api.*')) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
