<?php

namespace App\Enums;

enum MailType: string
{
    case Confirmation = 'confirmation';
    case PaymentReceived = 'payment_received';
    case BalancePayment = 'balance_payment';
    case Deposit = 'deposit';
    case PaymentRequest = 'payment_request';
    case Verified = 'verified';
    case Checkin = 'checkin';
    case Checkout = 'checkout';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Confirmation => 'Booking confirmation',
            self::PaymentReceived => 'Payment received',
            self::BalancePayment => 'Balance payment reminder',
            self::Deposit => 'Deposit request',
            self::PaymentRequest => 'Payment request',
            self::Verified => 'Identity verified',
            self::Checkin => 'Check-in instructions',
            self::Checkout => 'Check-out information',
            self::Review => 'Review request',
        };
    }
}
