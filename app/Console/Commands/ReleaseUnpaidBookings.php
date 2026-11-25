<?php

namespace App\Console\Commands;

use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Engines\NotificationEngine;
use App\Engines\PaymentEngine;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Coupon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bookings made on the site that were not paid within Booking::HOLD_MINUTES
 * give their dates back: otherwise a script (or a guest who walks away)
 * keeps the calendar full, also on Airbnb and Booking.com through the iCal
 * feed.
 *
 * Careful with money: first the provider is asked for the real status, so
 * whoever paid at the last minute stays booked. Open payment pages are closed
 * at the provider first; if one cannot be closed yet, the booking waits for
 * the next run, so nobody can pay for a cancelled booking.
 *
 * The guest then gets one email to book the same dates again (if the
 * accommodation has it switched on and the dates are still free).
 */
class ReleaseUnpaidBookings extends Command
{
    protected $signature = 'bookings:release-unpaid';

    protected $description = 'Cancels bookings made on the site that were not paid in time, so their dates are free again';

    public function handle(PaymentEngine $payments, BookingEngine $bookings, AvailabilityEngine $availability, NotificationEngine $notifications): int
    {
        $expired = Booking::query()
            ->where('status', BookingStatus::New->value)
            ->where('payment_status', PaymentStatus::Unpaid->value)
            ->whereNotNull('hold_until')
            ->where('hold_until', '<', now())
            ->get();

        $released = 0;

        foreach ($expired as $booking) {
            try {
                // Syncs every open payment with the provider (a paid one is
                // processed as paid) and closes the ones still open.
                $payments->letsOpenPaymentsExpire($booking);
                $booking->refresh();

                if ($booking->status !== BookingStatus::New || $booking->payment_status !== PaymentStatus::Unpaid) {
                    $booking->update(['hold_until' => null]); // paid after all

                    continue;
                }

                if ($booking->openPayments()->isNotEmpty()) {
                    continue; // the provider could not close it yet: next run
                }

                DB::transaction(function () use ($booking, $bookings): void {
                    $bookings->transition($booking, BookingStatus::Cancelled);
                    $booking->update(['hold_until' => null]);

                    // A coupon is only used up by a booking that stands.
                    $coupon = Coupon::search($booking->coupon_code);

                    if ($coupon !== null && $coupon->used_count > 0) {
                        $coupon->decrement('used_count');
                    }
                });

                $released++;
                $this->remindOfUnfinished($booking, $availability, $notifications);
                $this->line("{$booking->booking_number}: not paid in time, cancelled.");
            } catch (Throwable $e) {
                report($e);
                $this->error("{$booking->booking_number}: {$e->getMessage()}");
            }
        }

        $this->info($released === 0 ? 'No unpaid bookings to release.' : "{$released} booking(s) released.");

        return self::SUCCESS;
    }

    /**
     * One email to book the same dates again: only when it is switched on,
     * the arrival is still ahead and the dates are still free.
     */
    private function remindOfUnfinished(Booking $booking, AvailabilityEngine $availability, NotificationEngine $notifications): void
    {
        $property = $booking->property;

        if ($property === null || ! (bool) (int) $property->effective('unfinished_booking_mail') || $booking->arrival_date->lessThan(today())) {
            return;
        }

        // Taken in the meantime, for example by a booking that came in from Airbnb.
        if (! $availability->check($property, $booking->arrival_date, $booking->departure_date)->available) {
            return;
        }

        $notifications->sendOnce($booking, MailType::UnfinishedBooking);
    }
}
