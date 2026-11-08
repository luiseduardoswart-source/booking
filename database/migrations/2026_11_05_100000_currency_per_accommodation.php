<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prices per accommodation in a currency of their own (empty = the default), and each
 * booking remembers which currency it was booked in. Everything that already existed is
 * in euros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('base_price_cents');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR')->after('total_cents');
        });

        DB::table('properties')->where('is_master', true)->update(['currency' => 'EUR']);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
