<?php

use App\Http\Middleware\AuthenticateClaudeUser;
use App\Http\Middleware\EnsureClaudeConnectionEnabled;
use App\Mcp\Servers\BookingServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
| The Claude connection (App\Support\ClaudeConnection), loaded by laravel/mcp
| outside the web group: no session or CSRF, Claude sends a bearer token.
| Everything here only exists while the owner has switched it on.
|
| - /.well-known/... and /oauth/register: how Claude finds the login and
|   registers itself (only with a Claude return address, config/mcp.php).
| - /mcp: the tools, for a logged-in admin user with a valid token.
| The login itself (/oauth/authorize, /oauth/token) is Passport's.
*/

Route::middleware(EnsureClaudeConnectionEnabled::class)->group(function () {
    Route::middleware('throttle:30,1')->group(fn () => Mcp::oauthRoutes());

    Mcp::web('/mcp', BookingServer::class)
        ->middleware(['auth:api', AuthenticateClaudeUser::class, 'throttle:120,1']);
});
