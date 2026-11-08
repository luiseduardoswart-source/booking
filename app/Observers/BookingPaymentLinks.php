<?php

namespace App\Observers;

use App\Engines\PaymentEngine;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A cancelled booking can no longer be paid: open links expire
 * at the provider, whichever way the cancellation came in.
 */
class BookingPaymentLinks implements ShouldHandleEventsAfterCommit
{
    public function updated(Booking $booking): void
    {
        if ($booking->wasChanged('status') && $booking->status === BookingStatus::Cancelled) {
            app(PaymentEngine::class)->letsOpenPaymentsExpire($booking);
        }
    }
}
