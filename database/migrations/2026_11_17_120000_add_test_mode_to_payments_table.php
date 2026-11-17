<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a payment was made with a test key (no real money). Filled from
 * now on when the payment is created; for existing payments only where the
 * id gives it away (the fake provider, Stripe cs_test_ / cs_live_). The rest
 * stays unknown (null): bookings:clear-test-data asks about those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('test_mode')->nullable()->after('provider');
        });

        DB::table('payments')->where('provider', 'fake')->update(['test_mode' => true]);
        DB::table('payments')->where('provider', 'stripe')->where('provider_payment_id', 'like', 'cs_test_%')->update(['test_mode' => true]);
        DB::table('payments')->where('provider', 'stripe')->where('provider_payment_id', 'like', 'cs_live_%')->update(['test_mode' => false]);

        // A refund follows the payment it refunds.
        DB::table('payments as refund')
            ->join('payments as original', 'original.id', '=', 'refund.refunded_payment_id')
            ->whereNotNull('original.test_mode')
            ->select('refund.id', 'original.test_mode')
            ->get()
            ->each(fn ($row) => DB::table('payments')->where('id', $row->id)->update(['test_mode' => $row->test_mode]));
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('test_mode');
        });
    }
};
