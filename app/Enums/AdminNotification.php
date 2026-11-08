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

    public function title(): string
    {
        return match ($this) {
            self::NewBooking => __('New booking'),
            self::Cancellation => __('Booking cancelled'),
            self::Payment => __('Payment received'),
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
        return $this === self::Payment ? 'payments' : 'bookings';
    }

    /** The permission with which a host receives this notification. */
    public function permission(): string
    {
        return Access::VIEW;
    }
}
