<?php

namespace App\Console\Commands;

use App\Support\TestBookingsCleaner;
use Illuminate\Console\Command;

class ClearTestBookings extends Command
{
    protected $signature = 'bookings:clear-test-data';

    protected $description = 'Before going live: delete all test bookings with their payments, invoices and mails, and start the numbering at 0001 again';

    public function handle(TestBookingsCleaner $cleaner): int
    {
        $counts = $cleaner->counts();

        if ($counts['bookings'] === 0) {
            $this->info('No bookings: nothing to clear.');

            return self::SUCCESS;
        }

        $live = $cleaner->livePayments();

        if ($live->isNotEmpty()) {
            $this->error('Stopped: there is a real payment (made with a live key) among them. Nothing has been deleted.');
            $this->listPayments($live);

            return self::FAILURE;
        }

        $this->table(['What', 'Number'], [
            ['Bookings', $counts['bookings']],
            ['Payments and refunds', $counts['payments']],
            ['Invoices', $counts['invoices']],
            ['Sent emails', $counts['sent_mails']],
            ['Reviews', $counts['reviews']],
            ['Guest login links and sessions', $counts['guest_logins']],
        ]);
        $this->line('Also: their log lines and queued emails. Booking and invoice numbers start at 0001 again, coupon usage at 0.');
        $this->line('Accommodations, prices, settings, pages, users and blocked dates stay.');

        $unknown = $cleaner->unknownPayments();

        if ($unknown->isNotEmpty()) {
            $this->warn('Of these payments it is not known whether they were test payments:');
            $this->listPayments($unknown);

            if (! $this->confirm('These payments were made before this was remembered. Are they all test payments?')) {
                $this->info('Nothing has been deleted.');

                return self::FAILURE;
            }
        }

        if (! $this->confirm('Delete all of this permanently?')) {
            $this->info('Nothing has been deleted.');

            return self::FAILURE;
        }

        $cleaner->clear();
        $this->info('Done: the test bookings are gone and the numbering starts at 0001 again.');

        return self::SUCCESS;
    }

    private function listPayments($payments): void
    {
        $this->table(['Booking', 'Provider', 'Amount (cents)', 'Status'], $payments->map(fn ($payment) => [
            $payment->booking?->booking_number, $payment->provider, $payment->amount_cents, $payment->status->value,
        ])->all());
    }
}
