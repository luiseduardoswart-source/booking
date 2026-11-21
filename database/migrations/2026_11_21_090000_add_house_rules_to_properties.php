<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed house rules next to the owner's own text: smoking, parties and
 * events, commercial photography, quiet hours and locking the doors. Empty on
 * an accommodation = follow the default; empty on the default = not stated
 * (no rule shown). Nothing is filled in here: the owner chooses.
 */
return new class extends Migration
{
    private const COLUMNS = ['smoking', 'events_allowed', 'commercial_photography_allowed', 'quiet_hours_from', 'quiet_hours_until', 'lock_doors_on_departure'];

    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('smoking', 10)->nullable()->after('max_babies');
            $table->boolean('events_allowed')->nullable()->after('smoking');
            $table->boolean('commercial_photography_allowed')->nullable()->after('events_allowed');
            $table->time('quiet_hours_from')->nullable()->after('commercial_photography_allowed');
            $table->time('quiet_hours_until')->nullable()->after('quiet_hours_from');
            $table->boolean('lock_doors_on_departure')->nullable()->after('quiet_hours_until');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
