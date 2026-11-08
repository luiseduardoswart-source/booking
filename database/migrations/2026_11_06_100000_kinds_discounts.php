<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More kinds of discounts, as on Airbnb: besides "from X nights" also
 * last-minute (booked shortly before arrival) and early-bird (long in advance),
 * and a name the guest sees in the price breakdown. Existing discounts are
 * length-of-stay discounts. Per accommodation (or as the default) you choose whether
 * discounts that apply at the same time add up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->string('kind', 20)->default('stay_duration')->after('property_id');
            $table->string('name')->nullable()->after('kind');
            $table->json('name_translations')->nullable()->after('name');
            $table->unsignedSmallInteger('min_nights')->nullable()->change();
            $table->unsignedSmallInteger('days_before_arrival')->nullable()->after('min_nights');
            $table->unsignedTinyInteger('months_before_arrival')->nullable()->after('days_before_arrival');
        });

        // If several discounts apply at once, do they add up? Empty = follow the
        // default; the default starts at "no" (one discount).
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('discounts_add_up')->nullable()->after('prices_in_calendar');
        });

        DB::table('properties')->where('is_master', true)->update(['discounts_add_up' => false]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('discounts_add_up');
        });

        // Only length-of-stay discounts have a number of nights.
        DB::table('discounts')->where('kind', '!=', 'stay_duration')->delete();

        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn(['kind', 'name', 'name_translations', 'days_before_arrival', 'months_before_arrival']);
            $table->unsignedSmallInteger('min_nights')->nullable(false)->change();
        });
    }
};
