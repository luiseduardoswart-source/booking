<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;

/**
 * Sign-ins to the admin in the log (Logboek): who signed in, and every
 * failed attempt with the address that was tried, so the owner sees when
 * someone is guessing passwords. Found by Laravel's event discovery.
 */
class LogSignIns
{
    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User || ! in_array($event->user->role, UserRole::admin(), true)) {
            return;
        }

        activity()->causedBy($event->user)->performedOn($event->user)
            ->event('login')
            ->withProperties(['ip' => request()->ip()])
            ->log('Signed in');
    }

    public function handleFailed(Failed $event): void
    {
        $activity = activity()->event('login_failed')
            ->withProperties([
                'email' => mb_substr((string) ($event->credentials['email'] ?? ''), 0, 255),
                'ip' => request()->ip(),
            ]);

        if ($event->user instanceof User) {
            $activity->performedOn($event->user);
        }

        $activity->log('Sign-in failed');
    }
}
