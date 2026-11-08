<?php

use App\Models\PropertyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * For accommodation types too, English becomes the source language, like for
 * the facilities (see 2026_11_03_140000). The default types get their English
 * name; Dutch then comes from lang/nl.json. For a type added by the owner,
 * the name and the English translation are swapped: without an English
 * translation the name stays as it is until there is one.
 *
 * The slug does not change, so accommodations keep their type.
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
        foreach (PropertyType::query()->get() as $type) {
            $english = PropertyType::DEFAULT[$type->slug] ?? null;
            $default = $english === null ? null : [
                'en' => $english,
                'nl' => Lang::get($english, [], 'nl'),
            ];

            // A default type that still has its default name: only the name changes.
            if ($default !== null && $type->name === $default[$of]) {
                $type->update(['name' => $default[$to]]);

                continue;
            }

            $translations = $type->name_translations ?? [];
            $name = filled($translations[$to] ?? null) ? $translations[$to] : $type->name;
            unset($translations[$to]);
            $translations[$of] = $type->name;

            $type->update(['name' => $name, 'name_translations' => $translations]);
        }
    }
};
