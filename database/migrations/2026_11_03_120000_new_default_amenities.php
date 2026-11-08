<?php

use App\Models\Amenity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The amenities become the fixed list from Amenity::DEFAULT, so a
 * host only has to choose. Old default names with a successor in
 * the new list are renamed, so accommodations keep their choice; anything
 * not in the list (including self-added amenities) disappears.
 */
return new class extends Migration
{
    /** Old default name => the name in the new list. */
    private const RENAMED = [
        'WiFi' => 'Wifi',
        'TV' => 'Tv',
        'Airco' => 'Airconditioning',
        'Werkplek' => 'Eigen werkplek',
        'Wasdroger' => 'Droger',
        'Jacuzzi' => 'Bubbelbad',
        'Barbecue' => 'Barbecue/grill',
        'Gratis parkeren' => 'Gratis parkeren op het terrein',
        'Kinderbedje' => 'Wiegje',
        'EHBO-kit' => 'EHBO-doos',
        'Tuin' => 'Achtertuin',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::RENAMED as $old => $new) {
                $this->rename($old, $new);
            }

            // Back then still with the Dutch names; those become English later.
            $default = collect(Amenity::DEFAULT)->mapWithKeys(fn (string $icon, string $name) => [Amenity::dutchName($name) => $icon]);

            $gone = Amenity::query()->whereNotIn('name', $default->keys())->pluck('id');
            DB::table('amenity_property')->whereIn('amenity_id', $gone)->delete();
            Amenity::query()->whereKey($gone)->delete();

            foreach ($default as $name => $icon) {
                Amenity::query()->updateOrCreate(['name' => $name], ['icon' => $icon]);
            }
        });
    }

    /** If the new name already exists, the links move there. */
    private function rename(string $old, string $new): void
    {
        // With strcmp: MariaDB compares case-insensitively, so "WiFi" also finds "Wifi".
        $existing = Amenity::query()->where('name', $old)->get()->first(fn (Amenity $a) => strcmp($a->name, $old) === 0);

        if ($existing === null) {
            return;
        }

        $target = Amenity::query()->where('name', $new)->whereKeyNot($existing->getKey())->first();

        if ($target === null) {
            $existing->update(['name' => $new, 'name_translations' => null]);

            return;
        }

        $accommodations = DB::table('amenity_property')->where('amenity_id', $existing->getKey())->pluck('property_id');
        DB::table('amenity_property')->insertOrIgnore($accommodations->map(fn ($id) => ['property_id' => $id, 'amenity_id' => $target->getKey()])->all());
        DB::table('amenity_property')->where('amenity_id', $existing->getKey())->delete();
        $existing->delete();
    }

    public function down(): void
    {
        // Deliberately empty: restoring the old list would not bring back
        // the accommodations' choices.
    }
};
