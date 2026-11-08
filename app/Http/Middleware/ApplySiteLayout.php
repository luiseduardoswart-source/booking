<?php

namespace App\Http\Middleware;

use App\Support\SiteLayouts;
use Closure;
use Illuminate\Http\Request;

/**
 * Activates the chosen layout template: the template folder is put before the
 * default views in the view finder, so every view the folder contains
 * (pages and components) replaces the default version. Views the template
 * does not supply simply stay the built-in ones.
 */
class ApplySiteLayout
{
    public function handle(Request $request, Closure $next): mixed
    {
        $active = SiteLayouts::active();

        if ($active !== SiteLayouts::DEFAULT) {
            view()->getFinder()->prependLocation(SiteLayouts::path($active));
        }

        return $next($request);
    }
}
