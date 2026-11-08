<?php

use App\Models\Amenity;
use Illuminate\Database\Migrations\Migration;

/**
 * The default amenities were only in a seeder, and that does not run on an
 * installation via the wizard, so every new site started with an empty
 * list. Here we add what is missing, so existing installations also get the
 * list. Amenities added or renamed by the owner are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = Amenity::query()->pluck('name')->all();

        // Still with the Dutch names back then; they become English later.
        foreach (Amenity::DEFAULT as $english => $icon) {
            $name = Amenity::dutchName($english);

            if (in_array($name, $existing, true)) {
                continue;
            }

            Amenity::create(['name' => $name, 'icon' => $icon]);
        }
    }

    public function down(): void
    {
        // Deliberately empty: amenities may be linked to accommodations and
        // the owner may have changed them. Rolling back would discard data.
    }
};
