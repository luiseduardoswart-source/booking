<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Before going live: removes the test bookings and everything that hangs on
 * them (payments, invoices, sent mails, reviews, guest logins, their log
 * lines, queued mails and signature images), and starts the booking and invoice numbering at
 * 0001 again. Accommodations, prices, settings and blocked dates stay.
 *
 * Never with a real payment among them: the caller checks livePayments()
 * first. Deletes with plain queries, without model events, so nothing is
 * mailed, logged or synchronised on the way.
 */
class TestBookingsCleaner
{
    /** Tables that hang on bookings, children first; their numbering starts again. */
    private const TABLES = ['reviews', 'sent_mails', 'invoices', 'payments', 'bookings', 'guest_logins'];

    /** What would go, per table. */
    public function counts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    /**
     * Payments made with a live key: real money, so these are never cleared.
     * One the provider never received (no id of its own) moved no money.
     */
    public function livePayments(): Collection
    {
        return Payment::query()->with('booking:id,booking_number')
            ->where('test_mode', false)
            ->whereNotNull('provider_payment_id')
            ->get();
    }

    /** Payments from before the test key was remembered; only the owner knows. */
    public function unknownPayments(): Collection
    {
        return Payment::query()->with('booking:id,booking_number')->whereNull('test_mode')->get();
    }

    public function clear(): void
    {
        $signatures = DB::table('bookings')->whereNotNull('signature_path')->pluck('signature_path')->all();

        DB::transaction(function (): void {
            DB::table('activity_log')
                ->whereIn('subject_type', [(new Booking)->getMorphClass(), (new Payment)->getMorphClass(), (new Review)->getMorphClass()])
                ->delete();

            // Queued mails for bookings that will no longer exist.
            foreach (['jobs', 'failed_jobs'] as $table) {
                DB::table($table)->where('payload', 'like', '%BookingMail%')->delete();
            }

            DB::table('payments')->update(['refunded_payment_id' => null]);

            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }

            DB::table('settings')->where('group', 'documents')->where('key', 'like', 'invoiceNumber%')->delete();
            DB::table('coupons')->update(['used_count' => 0]);
        });

        // Outside the transaction: MariaDB commits an ALTER TABLE by itself.
        foreach (self::TABLES as $table) {
            $this->restartNumbering($table);
        }

        // Only after the rows are gone: a failed transaction keeps its files.
        Storage::disk('local')->delete($signatures);

        Setting::forgets();
    }

    /**
     * The next row gets id 1 again (the booking number follows the id).
     * MariaDB/MySQL can only do that with ALTER TABLE, which silently commits
     * an open transaction of the caller; inside one it is left alone. The
     * command itself never runs inside a transaction.
     */
    private function restartNumbering(string $table): void
    {
        $alterAllowed = DB::transactionLevel() === 0;

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => $alterAllowed ? DB::statement('ALTER TABLE '.DB::getTablePrefix().$table.' AUTO_INCREMENT = 1') : null,
            'sqlite' => DB::table('sqlite_sequence')->where('name', DB::getTablePrefix().$table)->delete(),
            'pgsql' => DB::statement("SELECT setval(pg_get_serial_sequence('".DB::getTablePrefix().$table."', 'id'), 1, false)"),
            default => null,
        };
    }
}
