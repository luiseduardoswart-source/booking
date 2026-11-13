<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order of the amenities per accommodation: the owner drags them, the
 * first ones appear on the page. Existing choices keep the order they had
 * (by amenity id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenity_property', function (Blueprint $table) {
            $table->unsignedSmallInteger('position')->nullable()->after('amenity_id');
        });

        DB::table('amenity_property')
            ->orderBy('property_id')
            ->orderBy('amenity_id')
            ->get(['property_id', 'amenity_id'])
            ->groupBy('property_id')
            ->each(function ($rows, $propertyId) {
                DB::table('amenity_property')->upsert(
                    $rows->values()->map(fn ($row, $index) => [
                        'property_id' => $propertyId, 'amenity_id' => $row->amenity_id, 'position' => $index + 1,
                    ])->all(),
                    ['property_id', 'amenity_id'],
                    ['position'],
                );
            });
    }

    public function down(): void
    {
        Schema::table('amenity_property', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
