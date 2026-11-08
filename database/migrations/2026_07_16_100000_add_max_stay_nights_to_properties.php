<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Hard upper limit on the length of stay, independent of the (optional)
            // booking rules per period — prevents a stay that is too long from
            // legally counting as a residential rental instead of short-term lodging.
            $table->unsignedInteger('max_stay_nights')->nullable()->after('rental_terms');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('max_stay_nights');
        });
    }
};
