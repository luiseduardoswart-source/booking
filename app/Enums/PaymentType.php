<?php

namespace App\Enums;

enum PaymentType: string implements \Filament\Support\Contracts\HasLabel
{
    case DownPayment = 'down_payment';
    case Balance = 'balance';
    case Deposit = 'deposit';
    case Refund = 'refund';

    public function getLabel(): string
    {
        return __($this->label());
    }

    public function label(): string
    {
        return match ($this) {
            self::DownPayment => 'Down payment',
            self::Balance => 'Balance payment',
            self::Deposit => 'Deposit',
            self::Refund => 'Refund',
        };
    }
}
