<?php

namespace App\Enums;

enum IdentityStatus: string implements \Filament\Support\Contracts\HasColor, \Filament\Support\Contracts\HasLabel
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Failed => 'Failed',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Verified => 'success',
            self::Failed => 'danger',
        };
    }
}
