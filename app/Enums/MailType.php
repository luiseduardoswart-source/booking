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
    case IdentityReminder = 'identity_reminder';
    case IdentityCancelled = 'identity_cancelled';

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
            self::IdentityReminder => 'Identity check reminder',
            self::IdentityCancelled => 'Cancelled: no identity check',
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
            self::Confirmation => 'After the first successful payment, which it names; right away for a booking made in the admin or without online payment',
            self::PaymentReceived => 'After each later payment, such as the balance or a separately paid deposit',
            self::BalancePayment => 'The number of days before arrival set here, if only the down payment has been paid',
            self::Deposit => 'The number of days before arrival set in the Payments tab under Deposit. Collected right after booking: paid along with the first payment, so only for bookings you make or confirm yourself',
            self::PaymentRequest => 'When you send it from a booking',
            self::Verified => 'After a successful identity check, with the payment link, if the guest left the site before paying',
            self::Checkin => 'The number of days before arrival set here',
            self::Checkout => 'The number of days before departure set here',
            self::Review => 'The number of days after departure set here',
            self::IdentityReminder => 'With the identity check after the payment: the number of days before its deadline set under Rules, if it has not been done yet',
            self::IdentityCancelled => 'With the identity check after the payment: when the booking is cancelled because it was not done by the deadline',
        };
    }
}
