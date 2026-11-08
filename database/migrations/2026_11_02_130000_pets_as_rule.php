<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pets are a rule, not an amenity: per accommodation on, off or follow the
 * default. Whoever had the amenity "Huisdieren toegestaan" gets the
 * rule on; after that the amenity disappears. Deliberately before the new list of
 * amenities, which would otherwise already remove this amenity.
 */
return new class extends Migration
{
    private const AMENITY = 'Huisdieren toegestaan';

    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('pets_allowed')->nullable()->after('pet_fee_cents');
        });

        DB::transaction(function () {
            $amenity = DB::table('amenities')->where('name', self::AMENITY)->value('id');

            if ($amenity !== null) {
                $accommodations = DB::table('amenity_property')->where('amenity_id', $amenity)->pluck('property_id');
                DB::table('properties')->whereIn('id', $accommodations)->update(['pets_allowed' => true]);
                DB::table('amenity_property')->where('amenity_id', $amenity)->delete();
                DB::table('amenities')->where('id', $amenity)->delete();
            }

            // Until now pets were only allowed where it was explicitly on.
            DB::table('properties')->where('is_master', true)->update(['pets_allowed' => false]);
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('pets_allowed');
        });
    }
};
