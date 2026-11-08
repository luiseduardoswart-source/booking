<?php

namespace App\Enums;

enum ReviewStatus: string implements \Filament\Support\Contracts\HasColor, \Filament\Support\Contracts\HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return __($this->label());
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
