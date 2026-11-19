<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * After the token check (auth:api): from here on the request works as the
 * real User, exactly as in the admin. That way Access (rights and a host's
 * own accommodations), the ownership check when saving and the activity
 * log ("changed by") all apply unchanged.
 *
 * Only someone who may log in to the admin gets through: a user whose role
 * was changed after connecting loses access at once.
 */
class AuthenticateClaudeUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = User::query()->find(Auth::guard('api')->id());

        abort_unless($user instanceof User && $user->canAccessPanel(Filament::getPanel('admin')), 403);

        Auth::shouldUse('web');
        Auth::guard('web')->setUser($user);
        $request->setUserResolver(fn () => $user);
        app()->setLocale($user->preferredLocale());

        return $next($request);
    }
}
