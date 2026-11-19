<?php

namespace App\Http\Middleware;

use App\Support\McpConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The AI connection is off until the owner switches it on in Settings → AI
 * connection. Until then its addresses (/mcp, /oauth/..., the .well-known
 * descriptions) do not exist. Once on, only the apps the owner allows can
 * register and log in.
 */
class EnsureMcpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(McpConnection::enabled(), 404);

        McpConnection::applyRedirectRules();

        // Also for an app that registered before the owner stopped allowing it.
        if ($request->routeIs('passport.authorizations.authorize') && filled($request->query('redirect_uri'))) {
            abort_unless(McpConnection::allowsRedirect((string) $request->query('redirect_uri')), 403, __('This app is not allowed to connect. The owner can allow it under Settings → AI connection.'));
        }

        return $next($request);
    }
}
