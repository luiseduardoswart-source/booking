<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Minimum number of nights per booking (always in effect, with
            // master fallback), alongside the period-bound booking rules.
            $table->unsignedInteger('min_stay_nights')->nullable()->after('max_stay_nights');

            // How far ahead guests can book (in months). Empty = unlimited.
            $table->unsignedInteger('booking_window_months')->nullable()->after('min_stay_nights');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['min_stay_nights', 'booking_window_months']);
        });
    }
};
