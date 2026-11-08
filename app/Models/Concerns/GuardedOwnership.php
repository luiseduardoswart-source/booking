<?php

namespace App\Models\Concerns;

use App\Support\Access;

/**
 * Final check right before saving and deleting: a logged-in host may only
 * change something about their own accommodations (see Access). Also works
 * if a request was forged outside the admin panel. Without a logged-in
 * host (the website, cron jobs, the owner) nothing changes.
 */
trait GuardedOwnership
{
    protected static function bootGuardedOwnership(): void
    {
        static::saving(fn (self $record) => Access::checkOwnership($record));
        static::deleting(fn (self $record) => Access::checkOwnership($record));
    }
}
