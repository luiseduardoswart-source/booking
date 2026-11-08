<?php

namespace App\Observers;

use App\Enums\AdminNotification;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Support\AdminNotifications;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Notifies the owner and host of a new and a cancelled booking, whichever way
 * it came in (website, admin, payment confirmation). Only after the commit to
 * the database, so a rolled-back booking sends no mail.
 */
class BookingNotifications implements ShouldHandleEventsAfterCommit
{
    public function created(Booking $booking): void
    {
        AdminNotifications::send(AdminNotification::NewBooking, $booking);
    }

    public function updated(Booking $booking): void
    {
        if ($booking->wasChanged('status') && $booking->status === BookingStatus::Cancelled) {
            AdminNotifications::send(AdminNotification::Cancellation, $booking);
        }
    }
}
