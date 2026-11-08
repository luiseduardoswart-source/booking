<?php

namespace App\Filament\Widgets;

use App\Support\Access;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Accommodations can each have their own currency: add up per currency
        // and then convert to the default currency.
        $revenueThisMonth = Money::inDefaultCurrency(Payment::revenuePerCurrency(
            Access::restrict(Payment::query())
                ->whereBetween('payments.paid_at', [now()->startOfMonth(), now()->endOfMonth()])
        ));

        $newBookings = Access::restrict(Booking::query())
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotIn('status', Booking::INACTIVE_STATUSES)
            ->count();

        // Outstanding: active bookings that are not fully paid yet.
        $outstanding = Access::restrict(Booking::query())
            ->whereIn('status', [BookingStatus::New->value, BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::DownPaymentPaid->value])
            // Paid amount per booking in the same query, not one query per booking.
            ->withSum(['payments as paid_cents' => fn ($payments) => $payments
                ->where('status', PaymentTransactionStatus::Paid->value)
                ->whereIn('type', [PaymentType::DownPayment->value, PaymentType::Balance->value])], 'amount_cents')
            // A deposit paid along with the stay does not pay for the stay.
            ->withSum(['payments as paid_deposit_cents' => fn ($payments) => $payments
                ->where('status', PaymentTransactionStatus::Paid->value)
                ->whereIn('type', [PaymentType::DownPayment->value, PaymentType::Balance->value])], 'deposit_part_cents')
            ->get()
            ->groupBy('currency')
            ->map(fn ($bookings) => $bookings->sum(fn (Booking $booking) => max(0, $booking->total_cents - ((int) $booking->paid_cents - (int) $booking->paid_deposit_cents))));
        $outstanding = Money::inDefaultCurrency($outstanding);

        $arrivalsToday = Access::restrict(Booking::query())
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Paid->value])
            ->whereDate('arrival_date', today())
            ->count();

        return [
            Stat::make(__('Revenue this month'), Money::format($revenueThisMonth))
                ->description(__('Received payments'))
                ->color('success'),
            Stat::make(__('New bookings'), $newBookings)
                ->description(__('Last 7 days'))
                ->color('info'),
            Stat::make(__('Outstanding payments'), Money::format((int) $outstanding))
                ->description(__('Still to receive'))
                ->color($outstanding > 0 ? 'warning' : 'success'),
            Stat::make(__('Arrivals today'), $arrivalsToday)
                ->color('primary'),
        ];
    }
}
