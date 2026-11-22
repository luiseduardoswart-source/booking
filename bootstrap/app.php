<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Unpacked zip package without .env: create one first, otherwise even the
// installation wizard cannot start (see FirstStart).
\App\Support\FirstStart::ensureEnv(dirname(__DIR__));

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Before everything, so outside the web group: that only runs after a route
        // is matched, so unknown paths would stay on the wrong hostname.
        // This also applies the canonical hostname to /api.
        $middleware->prepend(\App\Http\Middleware\CanonicalHost::class);

        // Browser security headers on every response (see the class).
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Payment provider webhooks and sendBeacon tracking have no
        // CSRF token.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'track/*',
        ]);

        // The cookie consent is set client-side (unencrypted) and read
        // server-side, so do not encrypt that cookie.
        $middleware->encryptCookies(except: ['cookie_consent']);

        // Not logged in on a page that requires it: the admin login, which
        // then returns to the page. The only such page outside the admin is
        // the AI connection's "Allow" screen (/oauth/authorize).
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        $middleware->web(append: [
            // First: without a user the site is not set up yet and
            // visitors go to the wizard. Must come before the middleware below,
            // because those query settings and pages, tables that do not
            // exist yet on a fresh installation.
            \App\Http\Middleware\RedirectToInstallation::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\ApplySiteLayout::class,
            \App\Http\Middleware\InjectStyleEditor::class,
            \App\Http\Middleware\ApplyContentLinks::class,
            // Pages that are not meant for search results say so (X-Robots-Tag).
            \App\Http\Middleware\NoIndex::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            // The site chat also speaks JSON (see SiteChatController), and so
            // does the AI connection (routes/ai.php): without a valid
            // token it must get a 401, not the login page.
            fn (Request $request) => $request->is('api/*', 'chat', 'mcp'),
        );
    })->create();

// Zip package: the public files are in public_html next to the app folder, not
// in public/. Also for artisan, so uploads and favicons end up in the right place.
if ($webDir = \App\Support\DirectoryLayout::publicDir($app->basePath())) {
    $app->usePublicPath($webDir);
}

return $app;
