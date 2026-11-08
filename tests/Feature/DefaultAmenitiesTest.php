<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The fixed list of amenities: a host only chooses, in every language.
 */
class DefaultAmenitiesTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_11_03_120000_new_default_amenities.php'))->up();
    }

    private function accommodation(): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);
    }

    public function test_every_default_amenity_has_a_translation_in_every_language(): void
    {
        foreach (['nl', 'en', 'es', 'de', 'fr', 'zh'] as $language) {
            $translations = json_decode(file_get_contents(lang_path("{$language}.json")), true);

            foreach (array_keys(Amenity::DEFAULT) as $name) {
                $this->assertNotEmpty($translations[$name] ?? null, "{$language}: {$name}");
            }
        }

        $this->assertSame('Bubbelbad', (new Amenity(['name' => 'Hot tub']))->localized('name', 'nl'));
        $this->assertSame('Hot tub', (new Amenity(['name' => 'Hot tub']))->localized('name', 'en'));
    }

    public function test_the_names_are_english_and_links_stay(): void
    {
        // The state before this change: Dutch names.
        Amenity::query()->delete();
        $washingMachine = Amenity::create(['name' => 'Wasmachine', 'icon' => 'washing-machine']);
        $tv = Amenity::create(['name' => 'Tv']);
        $wifi = Amenity::create(['name' => 'Wifi']);
        $bar = Amenity::create(['name' => 'Buitenbar', 'name_translations' => ['en' => 'Outdoor bar', 'de' => 'Außenbar']]);
        $sailboat = Amenity::create(['name' => 'Zeilboot']);
        $accommodation = $this->accommodation();
        $accommodation->amenities()->attach([$washingMachine->id, $tv->id, $wifi->id, $bar->id, $sailboat->id]);

        $migration = require database_path('migrations/2026_11_03_140000_amenities_english_as_source_language.php');
        $migration->up();

        $this->assertEqualsCanonicalizing(
            ['Washing machine', 'TV', 'Wifi', 'Outdoor bar', 'Zeilboot'],
            $accommodation->amenities()->pluck('name')->all(),
        );
        $this->assertSame('Washing machine', $washingMachine->fresh()->name);
        $this->assertSame('Wasmachine', $washingMachine->fresh()->localized('name', 'nl'));
        $this->assertSame('Waschmaschine', $washingMachine->fresh()->localized('name', 'de'));

        // Own amenities: the English translation becomes the name, Dutch becomes a translation.
        $this->assertSame(['de' => 'Außenbar', 'nl' => 'Buitenbar'], $bar->fresh()->name_translations);
        $this->assertSame('Buitenbar', $bar->fresh()->localized('name', 'nl'));
        $this->assertSame(['nl' => 'Zeilboot'], $sailboat->fresh()->name_translations);

        $migration->down();

        $this->assertSame('Wasmachine', $washingMachine->fresh()->name);
        $this->assertSame('Tv', $tv->fresh()->name);
        $this->assertSame('Buitenbar', $bar->fresh()->name);
        $this->assertSame(['de' => 'Außenbar', 'en' => 'Outdoor bar'], $bar->fresh()->name_translations);
        $this->assertSame(5, $accommodation->amenities()->count());
    }

    public function test_exists_the_english_name_already_then_go_the_links_there(): void
    {
        Amenity::query()->delete();
        $old = Amenity::create(['name' => 'Droger']);
        $new = Amenity::create(['name' => 'Dryer']);
        $accommodation = $this->accommodation();
        $accommodation->amenities()->attach($old->id);

        (require database_path('migrations/2026_11_03_140000_amenities_english_as_source_language.php'))->up();

        $this->assertNull(Amenity::find($old->id));
        $this->assertSame([$new->id], $accommodation->amenities()->pluck('amenities.id')->all());
    }

    public function test_the_old_list_is_the_new_and_links_stay(): void
    {
        // The state before this list: old names, one own amenity.
        Amenity::query()->delete();
        $wifi = Amenity::create(['name' => 'WiFi', 'icon' => 'wifi']);
        $garden = Amenity::create(['name' => 'Tuin', 'icon' => 'sprout']);
        $custom = Amenity::create(['name' => 'Uitzicht op zee']);
        $accommodation = $this->accommodation();
        $accommodation->amenities()->attach([$wifi->id, $garden->id, $custom->id]);

        $this->migrate();

        $this->assertSame(count(Amenity::DEFAULT), Amenity::count());
        $this->assertEqualsCanonicalizing(['Wifi', 'Achtertuin'], $accommodation->amenities()->pluck('name')->all());
        $this->assertSame($wifi->id, Amenity::firstWhere('name', 'Wifi')->id);
        $this->assertNull(Amenity::find($custom->id));
        $this->assertSame(0, DB::table('amenity_property')->where('amenity_id', $custom->id)->count());
        $this->assertSame(Amenity::DEFAULT['Hot tub'], Amenity::firstWhere('name', 'Bubbelbad')->iconName());
    }

    public function test_exists_the_new_name_already_then_go_the_links_there(): void
    {
        Amenity::query()->delete();
        $old = Amenity::create(['name' => 'Jacuzzi']);
        $new = Amenity::create(['name' => 'Bubbelbad']);
        $accommodation = $this->accommodation();
        $accommodation->amenities()->attach($old->id);

        $this->migrate();
        $this->migrate(); // once more: changes nothing anymore

        $this->assertSame([$new->id], $accommodation->amenities()->pluck('amenities.id')->all());
        $this->assertSame(count(Amenity::DEFAULT), Amenity::count());
    }

    public function test_every_default_amenity_has_a_category(): void
    {
        $arranged = array_merge(...array_values(Amenity::DEFAULT_CATEGORY));

        $this->assertEqualsCanonicalizing(array_keys(Amenity::DEFAULT), $arranged);
        $this->assertSame([], array_diff(array_keys(Amenity::DEFAULT_CATEGORY), array_keys(Amenity::CATEGORIES)));

        // A fresh installation has them in the database too; without a category it is Other.
        $this->assertSame('internet', Amenity::firstWhere('name', 'Wifi')->resolvedCategory());
        $this->assertSame(Amenity::OTHER, Amenity::create(['name' => 'Zeilboot'])->resolvedCategory());
    }
}
