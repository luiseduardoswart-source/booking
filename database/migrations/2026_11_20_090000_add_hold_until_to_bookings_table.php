<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Until when a booking made on the site holds its dates without being paid
 * (bookings:release-unpaid). Empty for bookings made in the admin or through
 * the API, and for bookings without an online payment: those never expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dateTime('hold_until')->nullable()->after('payment_status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['hold_until']);
            $table->dropColumn('hold_until');
        });
    }
};
