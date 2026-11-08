<?php

namespace App\Console\Commands;

use App\Engines\PaymentEngine;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ProcessDeposits extends Command
{
    protected $signature = 'deposits:process';

    protected $description = 'Processes deposit refunds after departure: refund automatically or remind the admin';

    public function handle(PaymentEngine $payments): int
    {
        $days = Property::valuePerAccommodation('deposit_days_after_departure');
        $automatic = Property::valuePerAccommodation('deposit_auto_refund');

        // Paid deposits of departed guests that have not been (partly) refunded yet,
        // once the period of their accommodation has passed.
        $outstanding = Payment::query()
            ->where('type', PaymentType::Deposit->value)
            ->where('status', PaymentTransactionStatus::Paid->value)
            ->whereDoesntHave('refunds')
            ->whereHas('booking', fn ($query) => $query
                ->whereDate('departure_date', '<=', today())
                ->whereNotIn('status', \App\Models\Booking::INACTIVE_STATUSES))
            ->with('booking.property')
            ->get()
            ->filter(fn (Payment $deposit) => $deposit->booking->departure_date
                ->addDays((int) $days[$deposit->booking->property_id])
                ->lte(today()));

        if ($outstanding->isEmpty()) {
            $this->info('No deposit refunds to process.');

            return self::SUCCESS;
        }

        [$refund, $remind] = $outstanding->partition(fn (Payment $deposit) => (bool) $automatic[$deposit->booking->property_id]);

        foreach ($refund as $deposit) {
            try {
                $payments->refund($deposit);
                $this->info("Deposit {$deposit->booking->booking_number} refunded (".\App\Support\Money::format($deposit->amount_cents, $deposit->booking->currency).').');
            } catch (\Throwable $e) {
                $this->error("Deposit {$deposit->booking->booking_number}: {$e->getMessage()}");
                report($e);
            }
        }

        if ($remind->isEmpty()) {
            return self::SUCCESS;
        }

        // Manual mode: daily reminder to the admin.
        $recipient = Setting::get('company', 'email', config('mail.from.address'));

        Mail::to($recipient)->send(new \App\Mail\DepositReminderMail($remind));
        $this->info($remind->count().' outstanding deposit(s) reported to '.$recipient.'.');

        return self::SUCCESS;
    }
}
