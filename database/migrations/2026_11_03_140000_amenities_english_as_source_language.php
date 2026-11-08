<?php

use App\Models\Amenity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * English also becomes the source language for amenities, like everywhere else (see
 * 2026_11_01_170000_english_as_source_language). The default amenities get their
 * English name; Dutch then comes from lang/nl.json. For an amenity added by the
 * owner, the name and the English translation are swapped, as with the
 * accommodations: without an English translation the name stays until there is one.
 *
 * Links to accommodations are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(fn () => $this->swap(of: 'nl', to: 'en'));
    }

    public function down(): void
    {
        DB::transaction(fn () => $this->swap(of: 'en', to: 'nl'));
    }

    private function swap(string $of, string $to): void
    {
        $default = [];

        foreach (array_keys(Amenity::DEFAULT) as $english) {
            [$old, $new] = $to === 'en'
                ? [Amenity::dutchName($english), $english]
                : [$english, Amenity::dutchName($english)];

            $default[] = $new;

            if ($old !== $new) {
                $this->rename($old, $new);
            }
        }

        foreach (Amenity::query()->get() as $amenity) {
            if (in_array($amenity->name, $default, true)) {
                continue;
            }

            $translations = $amenity->name_translations ?? [];
            $name = filled($translations[$to] ?? null) ? $translations[$to] : $amenity->name;
            unset($translations[$to]);
            $translations[$of] = $amenity->name;

            $this->rename($amenity->name, $name, $translations);
        }
    }

    /**
     * If the new name already exists, the links move there and the
     * old amenity is dropped.
     *
     * @param  array<string, string>|null  $translations  null leaves them alone
     */
    private function rename(string $old, string $new, ?array $translations = null): void
    {
        // With strcmp: MariaDB compares case-insensitively, so "Tv" also finds "TV".
        $existing = Amenity::query()->where('name', $old)->get()->first(fn (Amenity $a) => strcmp($a->name, $old) === 0);

        if ($existing === null) {
            return;
        }

        $target = Amenity::query()->where('name', $new)->whereKeyNot($existing->getKey())->first();

        if ($target === null) {
            $existing->update(['name' => $new, ...($translations === null ? [] : ['name_translations' => $translations ?: null])]);

            return;
        }

        $accommodations = DB::table('amenity_property')->where('amenity_id', $existing->getKey())->pluck('property_id');
        DB::table('amenity_property')->insertOrIgnore($accommodations->map(fn ($id) => ['property_id' => $id, 'amenity_id' => $target->getKey()])->all());
        DB::table('amenity_property')->where('amenity_id', $existing->getKey())->delete();
        $existing->delete();
    }
};
