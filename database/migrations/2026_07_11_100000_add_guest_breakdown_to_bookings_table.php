<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Breakdown like Airbnb: guests remains leading (adults + children);
            // infants and pets do not count towards the maximum.
            $table->unsignedTinyInteger('adults')->nullable()->after('guests');
            $table->unsignedTinyInteger('children')->nullable()->after('adults');
            $table->unsignedTinyInteger('infants')->nullable()->after('children');
            $table->unsignedTinyInteger('pets')->nullable()->after('infants');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['adults', 'children', 'infants', 'pets']);
        });
    }
};
