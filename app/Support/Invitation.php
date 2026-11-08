<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\AdminInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Invitation to the admin: a signed link with which someone chooses their own
 * password. The owner therefore never sees or shares that password.
 *
 * The link is valid for 7 days and works only once: it contains a check that
 * depends on the current password, so after choosing a new password it is
 * invalid. Resending is always possible, also when someone has lost their
 * password.
 */
final class Invitation
{
    public const VALID_DAYS = 7;

    public static function link(User $user): string
    {
        return URL::temporarySignedRoute(
            'admin.invitation',
            now()->addDays(self::VALID_DAYS),
            ['user' => $user->getKey(), 'check' => self::check($user)],
        );
    }

    /** Sends the invitation by mail and returns the link. */
    public static function send(User $user): string
    {
        $link = self::link($user);
        $user->notify(new AdminInvitation($link));

        return $link;
    }

    /** Signed, not expired, not yet used and for an admin account. */
    public static function isValid(Request $request, User $user): bool
    {
        return $request->hasValidSignature()
            && in_array($user->role, UserRole::admin(), true)
            && hash_equals(self::check($user), (string) $request->query('check'));
    }

    private static function check(User $user): string
    {
        return substr(hash_hmac('sha256', $user->getKey().'|'.$user->getAuthPassword(), (string) config('app.key')), 0, 40);
    }
}
