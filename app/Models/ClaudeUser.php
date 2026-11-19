<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * An admin user as the Claude connection's OAuth guard ('api', Passport)
 * sees them: the same row in `users`, but with Passport's token methods.
 * The User model itself cannot carry those, because it already has
 * Sanctum's (for /api) with the same names.
 *
 * Nothing works with this model directly: AuthenticateClaudeUser turns it
 * into the real User straight after the token check, so rights, ownership
 * checks and the activity log see the user as in the admin.
 */
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class ClaudeUser extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'users';
}
