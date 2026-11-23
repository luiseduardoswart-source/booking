<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\SmartLocks\SmartLockCodes;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A cancelled stay loses its smart lock code straight away, whichever way it
 * was cancelled (admin, guest, expired ID check). When the lock cannot be
 * reached now, locks:sync tries again (see SmartLockCodes::removeUnneeded).
 */
class BookingLockCodes implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly SmartLockCodes $codes) {}

    public function updated(Booking $booking): void
    {
        if ($booking->lock_code_id !== null && $booking->wasChanged('status') && ! $this->codes->entitled($booking)) {
            $this->codes->remove($booking);
        }
    }
}
