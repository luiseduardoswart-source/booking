<?php

use App\Http\Middleware\AuthenticateMcpUser;
use App\Http\Middleware\EnsureMcpEnabled;
use App\Mcp\Servers\BookingServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
| The AI connection (App\Support\McpConnection), loaded by laravel/mcp outside
| the web group: no session or CSRF, the app sends a bearer token. Everything
| here only exists while the owner has switched it on.
|
| - /.well-known/... and /oauth/register: how an AI app finds the login and
|   registers itself (only the apps the owner allows).
| - /mcp: the tools, for a logged-in admin user with a valid token.
| The login itself (/oauth/authorize, /oauth/token) is Passport's.
*/

Route::middleware(EnsureMcpEnabled::class)->group(function () {
    Route::middleware('throttle:30,1')->group(fn () => Mcp::oauthRoutes());

    Mcp::web('/mcp', BookingServer::class)
        ->middleware(['auth:api', AuthenticateMcpUser::class, 'throttle:120,1']);
});
