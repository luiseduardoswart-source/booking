<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two maps, two choices: location_display is the map on the accommodation's
 * own page, overview_location_display the map on the overview page with all
 * accommodations. Each: none, approximately or exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('overview_location_display', 15)->default('approximate')->after('location_display');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('overview_location_display');
        });
    }
};
