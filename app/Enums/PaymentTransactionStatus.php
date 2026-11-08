<?php

namespace App\Enums;

enum PaymentTransactionStatus: string implements \Filament\Support\Contracts\HasColor, \Filament\Support\Contracts\HasLabel
{
    case Open = 'open';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Open => 'Open',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Paid => 'success',
            self::Failed, self::Cancelled => 'danger',
            self::Expired => 'gray',
            self::Refunded => 'info',
        };
    }
}
