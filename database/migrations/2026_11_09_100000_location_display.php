<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the site shows where an accommodation is: approximately (a circle
 * around the area, the exact point hidden) or exactly (a pin on the
 * address). Approximately is what the site already promised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('location_display', 15)->default('approximate')->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('location_display');
        });
    }
};
