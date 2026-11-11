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

    /**
     * When the guest receives this email, as the owner reads it in the
     * "Automatic emails" tab. Keep in line with SendScheduledMails and the
     * places that send the email right away.
     */
    public function moment(): string
    {
        return match ($this) {
            self::Confirmation => 'Right after booking',
            self::PaymentReceived => 'Right after each payment',
            self::BalancePayment => 'The number of days before arrival set here, if only the down payment has been paid',
            self::Deposit => 'Right after booking, or the number of days before arrival set in the Payments tab under Deposit',
            self::PaymentRequest => 'When you send it from a booking',
            self::Verified => 'Right after a successful identity check',
            self::Checkin => 'The number of days before arrival set here',
            self::Checkout => 'The number of days before departure set here',
            self::Review => 'The number of days after departure set here',
        };
    }
}
