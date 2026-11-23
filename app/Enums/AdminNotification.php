<?php

namespace App\Enums;

use App\Models\Booking;
use App\Support\Access;

/**
 * Email notifications to the owner and the host of the accommodation
 * (see App\Support\AdminNotifications).
 */
enum AdminNotification: string
{
    case NewBooking = 'new_booking';
    case Cancellation = 'cancellation';
    case Payment = 'payment';
    case IdentityMissing = 'identity_missing';
    case PaymentReturned = 'payment_returned';
    case SmartLockFailed = 'smart_lock_failed';
    case SmartLockNotRemoved = 'smart_lock_not_removed';

    public function title(): string
    {
        return match ($this) {
            self::NewBooking => __('New booking'),
            self::Cancellation => __('Booking cancelled'),
            self::Payment => __('Payment received'),
            self::IdentityMissing => __('Identity check not done'),
            self::PaymentReturned => __('Payment returned to the guest'),
            self::SmartLockFailed => __('No door code from the smart lock'),
            self::SmartLockNotRemoved => __('Door code still on the smart lock'),
        };
    }

    public function subject(Booking $booking): string
    {
        return __(':title: :number, :accommodation', [
            'title' => $this->title(),
            'number' => $booking->booking_number,
            'accommodation' => $booking->property?->name,
        ]);
    }

    /** The screen a host must be allowed to view to receive this notification. */
    public function screen(): string
    {
        return in_array($this, [self::Payment, self::PaymentReturned], true) ? 'payments' : 'bookings';
    }

    /** The permission with which a host receives this notification. */
    public function permission(): string
    {
        return Access::VIEW;
    }
}
