<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The check-out email: a number of days before departure, per accommodation
 * (empty = follow the default). The default keeps what it did: 1 day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedSmallInteger('checkout_days_before_departure')->nullable()->after('checkin_days_before_arrival');
        });

        DB::table('properties')->where('is_master', true)->update(['checkout_days_before_departure' => 1]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('checkout_days_before_departure');
        });
    }
};
