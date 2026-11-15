<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A guest's way into "My bookings": a one-time login link (kind link) or a
 * login the browser remembers (kind session). Only the hash of the code is
 * stored. See App\Support\GuestAccess.
 */
#[Fillable(['email', 'kind', 'token_hash', 'expires_at', 'used_at', 'last_used_at'])]
class GuestLogin extends Model
{
    public const LINK = 'link';

    public const SESSION = 'session';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
