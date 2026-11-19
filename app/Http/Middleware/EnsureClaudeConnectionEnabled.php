<?php

namespace App\Http\Middleware;

use App\Support\ClaudeConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Claude connection is off until the owner switches it on in
 * Settings → Claude. Until then its addresses (/mcp, /oauth/..., the
 * .well-known descriptions) do not exist.
 */
class EnsureClaudeConnectionEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(ClaudeConnection::enabled(), 404);

        return $next($request);
    }
}
