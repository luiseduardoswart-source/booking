<?php

namespace App\Enums;

enum UserRole: string
{
    /** The platform owner: may do everything, including managing users and permissions. */
    case Administrator = 'administrator';
    case Staff = 'staff';
    /** A host: only sees their own accommodations, within the permissions the owner grants. */
    case Host = 'host';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => __('Owner'),
            self::Staff => __('Employee'),
            self::Host => __('Host'),
            self::Guest => __('Guest'),
        };
    }

    /** Roles that can log in to the admin. */
    public static function admin(): array
    {
        return [self::Administrator, self::Staff, self::Host];
    }
}
