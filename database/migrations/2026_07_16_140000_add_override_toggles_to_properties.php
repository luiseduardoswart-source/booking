<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Can be overridden per accommodation; null = follow the general setting.
            $table->boolean('id_required')->nullable()->after('rental_terms');
            $table->boolean('prices_in_calendar')->nullable()->after('id_required');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['id_required', 'prices_in_calendar']);
        });
    }
};
