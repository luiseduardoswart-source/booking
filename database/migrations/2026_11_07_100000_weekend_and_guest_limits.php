<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Weekend adjustment as a fixed amount or as a percentage of the nightly price
 *   (empty = follow the default).
 * - Maximum number of infants and pets per booking (empty = the default).
 *
 * What was already there keeps working the same: an accommodation with its own
 * weekend price gets "fixed amount", the rest follow the default. The
 * default starts at the limits the guest picker already used (5 and 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('weekend_kind', 10)->nullable()->after('weekend_price_cents');
            $table->smallInteger('weekend_percentage')->nullable()->after('weekend_kind');
            $table->unsignedTinyInteger('max_babies')->nullable()->after('max_guests');
            $table->unsignedTinyInteger('max_pets')->nullable()->after('pets_allowed');
        });

        DB::table('properties')->where('is_master', true)
            ->update(['weekend_kind' => 'fixed', 'max_babies' => 5, 'max_pets' => 2]);

        DB::table('properties')->where('is_master', false)->whereNotNull('weekend_price_cents')
            ->update(['weekend_kind' => 'fixed']);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['weekend_kind', 'weekend_percentage', 'max_babies', 'max_pets']);
        });
    }
};
