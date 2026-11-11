<?php

namespace App\Console\Commands;

use App\Engines\NotificationEngine;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Property;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SendScheduledMails extends Command
{
    /** "Days before arrival" that covers every coming stay (deposit right after booking). */
    private const WHOLE_FUTURE = 3650;

    protected $signature = 'mails:send-scheduled';

    protected $description = 'Sends the scheduled guest mails: balance payment, check-in, check-out and review request';

    public function handle(NotificationEngine $notifications): int
    {
        $sent = 0;

        $active = [BookingStatus::Confirmed->value, BookingStatus::Paid->value];

        // 1. Balance payment reminder: X days before arrival, not yet fully paid.
        $sent += $this->send($notifications, MailType::BalancePayment, 'balance_payment_days_before_arrival', fn (int $days) => Booking::query()
            ->whereIn('status', $active)
            ->where('payment_status', PaymentStatus::DownPaymentPaid->value)
            ->whereDate('arrival_date', '<=', today()->addDays($days))
            ->whereDate('arrival_date', '>=', today()));

        // 1b. Deposit request: X days before arrival, if a deposit applies and it has
        //     not been paid yet. Accommodations that collect it right after booking:
        //     every coming stay (normally the request already went out with the
        //     first payment; this catches bookings confirmed by hand).
        $depositMoment = Property::valuePerAccommodation('deposit_moment');
        $depositDays = Property::valuePerAccommodation('deposit_days_before_arrival')
            ->map(fn ($days, int $id) => $depositMoment[$id] === Property::DEPOSIT_AT_BOOKING ? self::WHOLE_FUTURE : $days);

        $sent += $this->send($notifications, MailType::Deposit, $depositDays, fn (int $days) => Booking::query()
            ->whereIn('status', $active)
            ->where('deposit_cents', '>', 0)
            ->whereDate('arrival_date', '<=', today()->addDays($days))
            ->whereDate('arrival_date', '>=', today())
            ->withoutPaidDeposit());

        // 2. Check-in instructions: X days before arrival.
        $sent += $this->send($notifications, MailType::Checkin, 'checkin_days_before_arrival', fn (int $days) => Booking::query()
            ->whereIn('status', $active)
            ->whereDate('arrival_date', '<=', today()->addDays($days))
            ->whereDate('arrival_date', '>=', today()));

        // 3. Check-out information: exactly X days before departure.
        $sent += $this->send($notifications, MailType::Checkout, 'checkout_days_before_departure', fn (int $days) => Booking::query()
            ->whereIn('status', $active)
            ->whereDate('departure_date', today()->addDays($days)));

        // 4. Review request: X days after departure.
        $sent += $this->send($notifications, MailType::Review, 'review_days_after_departure', fn (int $days) => Booking::query()
            ->whereIn('status', [...$active, BookingStatus::Completed->value])
            ->whereDate('departure_date', today()->subDays($days)));

        $this->info("{$sent} mail(s) sent.");

        return self::SUCCESS;
    }

    /**
     * Each accommodation can have its own number of days. One query per
     * number, not per accommodation: usually the same applies everywhere.
     *
     * @param  string|Collection<int, mixed>  $days  the field with the days, or days per accommodation id
     * @param  Closure(int): Builder  $bookings
     */
    private function send(NotificationEngine $notifications, MailType $type, string|Collection $days, Closure $bookings): int
    {
        $sent = 0;

        (is_string($days) ? Property::valuePerAccommodation($days) : $days)
            ->groupBy(fn ($days) => (int) $days, preserveKeys: true)
            ->each(function ($accommodations, int $days) use ($notifications, $type, $bookings, &$sent) {
                $bookings($days)
                    ->whereIn('property_id', $accommodations->keys())
                    ->each(function (Booking $booking) use ($notifications, $type, &$sent) {
                        $sent += (int) $notifications->sendOnce($booking, $type);
                    });
            });

        return $sent;
    }
}
