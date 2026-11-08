<?php

namespace App\Enums;

enum PaymentStatus: string implements \Filament\Support\Contracts\HasColor, \Filament\Support\Contracts\HasLabel
{
    case Unpaid = 'unpaid';
    case DownPaymentPaid = 'down_payment_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::DownPaymentPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Refunded => 'Refunded',
        };
    }

    public function getLabel(): string
    {
        return __($this->label());
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::DownPaymentPaid => 'warning',
            self::Paid => 'success',
            self::Refunded => 'gray',
        };
    }
}
