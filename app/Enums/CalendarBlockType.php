<?php

namespace App\Enums;

enum CalendarBlockType: string implements \Filament\Support\Contracts\HasLabel
{
    case Blocked = 'blocked';
    case Maintenance = 'maintenance';
    case External = 'external';

    public function getLabel(): string
    {
        return __($this->label());
    }

    public function label(): string
    {
        return match ($this) {
            self::Blocked => 'Blocked',
            self::Maintenance => 'Maintenance',
            self::External => 'External (synchronisation)',
        };
    }
}
