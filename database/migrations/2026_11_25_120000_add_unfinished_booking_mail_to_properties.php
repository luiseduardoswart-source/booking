<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a guest who did not pay in time gets one email to book the same
 * dates again. Empty on an accommodation = follow the default; the default
 * starts switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('unfinished_booking_mail')->nullable()->after('review_days_after_departure');
        });

        DB::table('properties')->where('is_master', true)->update(['unfinished_booking_mail' => true]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('unfinished_booking_mail');
        });
    }
};
