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

class SendScheduledMails extends Command
{
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
        //     not been paid yet.
        $sent += $this->send($notifications, MailType::Deposit, 'deposit_days_before_arrival', fn (int $days) => Booking::query()
            ->whereIn('status', $active)
            ->where('deposit_cents', '>', 0)
            ->whereDate('arrival_date', '<=', today()->addDays($days))
            ->whereDate('arrival_date', '>=', today())
            ->whereDoesntHave('payments', fn ($query) => $query
                ->where('type', \App\Enums\PaymentType::Deposit->value)
                ->where('status', \App\Enums\PaymentTransactionStatus::Paid->value)));

        // 2. Check-in instructions: X days before arrival.
        $sent += $this->send($notifications, MailType::Checkin, 'checkin_days_before_arrival', fn (int $days) => Booking::query()
            ->whereIn('status', $active)
            ->whereDate('arrival_date', '<=', today()->addDays($days))
            ->whereDate('arrival_date', '>=', today()));

        // 3. Check-out information: 1 day before departure.
        Booking::query()
            ->whereIn('status', $active)
            ->whereDate('departure_date', today()->addDay())
            ->each(function (Booking $booking) use ($notifications, &$sent) {
                $sent += (int) $notifications->sendOnce($booking, MailType::Checkout);
            });

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
     * @param  Closure(int): Builder  $bookings
     */
    private function send(NotificationEngine $notifications, MailType $type, string $field, Closure $bookings): int
    {
        $sent = 0;

        Property::valuePerAccommodation($field)
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
