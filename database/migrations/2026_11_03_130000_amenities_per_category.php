<?php

use App\Models\Amenity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A category per amenity, for the picker on an accommodation. The
 * default amenities get their category; the rest sit under Other
 * until the owner picks one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('icon');
        });

        // Back then still with the Dutch names; those become English later.
        foreach (Amenity::DEFAULT_CATEGORY as $category => $names) {
            Amenity::query()->whereIn('name', array_map(Amenity::dutchName(...), $names))->whereNull('category')->update(['category' => $category]);
        }
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
