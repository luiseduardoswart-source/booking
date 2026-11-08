<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * Enforces two-step verification as soon as the owner turns it on under
 * Settings → Security.
 *
 * Filament decides when registering the routes whether 2FA is required, and
 * those routes are cached on deploy. That is why "required" is always on in
 * the panel and this middleware checks the setting per request: the switch
 * then works immediately, without a new deploy.
 */
class TwoStepRequired extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        return static::isRequired() ? parent::handle($request, $next) : $next($request);
    }

    public static function isRequired(): bool
    {
        return Setting::get('security', 'two_step_required', '0') === '1';
    }
}
