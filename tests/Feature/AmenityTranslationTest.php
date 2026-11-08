<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Amenities\Pages\ManageAmenities;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\User;
use App\Services\MachineTranslator;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AmenityTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_amenity_used_the_built_in_translation(): void
    {
        $amenity = Amenity::firstWhere('name', 'Washing machine');

        app()->setLocale('en');
        $this->assertSame('Washing machine', $amenity->localizedName());

        app()->setLocale('de');
        $this->assertSame('Waschmaschine', $amenity->localizedName());

        // Dutch is an ordinary translation, from lang/nl.json.
        app()->setLocale('nl');
        $this->assertSame('Wasmachine', $amenity->localizedName());
    }

    public function test_own_translation_goes_for_on_everything(): void
    {
        $amenity = Amenity::firstWhere('name', 'Washing machine');
        $amenity->update(['name_translations' => ['en' => 'Laundry machine']]);

        app()->setLocale('en');
        $this->assertSame('Laundry machine', $amenity->localizedName());

        // Languages without their own translation fall back on the built-in translation.
        app()->setLocale('de');
        $this->assertSame('Waschmaschine', $amenity->localizedName());
    }

    public function test_own_amenity_without_translation_falls_back_on_the_english_name(): void
    {
        $amenity = Amenity::create(['name' => 'Finnish sauna']);

        app()->setLocale('fr');
        $this->assertSame('Finnish sauna', $amenity->localizedName());
    }

    public function test_translate_button_fills_only_what_really_is_missing(): void
    {
        // Fake translation service: records what is actually offered.
        $offered = new \ArrayObject;
        $this->app->bind(MachineTranslator::class, fn () => new class($offered) implements MachineTranslator
        {
            public function __construct(private \ArrayObject $offered) {}

            public function translate(string $text, string $from, string $to): string
            {
                $this->offered[] = "{$text}:{$to}";

                return "{$text} [{$to}]";
            }
        });

        $default = Amenity::firstWhere('name', 'Washing machine');          // has built-in translations
        $custom = Amenity::create(['name' => 'Finnish sauna', 'name_translations' => ['nl' => 'Finse sauna']]);

        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(ManageAmenities::class)
            ->callAction(TestAction::make('translate')->table());

        $request = $offered->getArrayCopy();

        // The default amenity is skipped entirely (already in the language files).
        $this->assertEmpty(array_filter($request, fn ($r) => str_starts_with($r, 'Washing machine:')));
        $this->assertSame([], $default->fresh()->name_translations ?? []);

        // For the custom amenity only 'nl' is skipped (it already had a
        // translation), and translation starts from the English name.
        $this->assertNotContains('Finnish sauna:nl', $request);
        $this->assertNotContains('Finnish sauna:en', $request);
        $this->assertContains('Finnish sauna:de', $request);

        $translations = $custom->fresh()->name_translations;
        $this->assertSame('Finse sauna', $translations['nl']);
        $this->assertSame('Finnish sauna [de]', $translations['de']);
    }

    public function test_own_amenity_appears_translated_on_the_site(): void
    {
        $property = Property::create([
            'name' => 'Saunahuis', 'slug' => 'saunahuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);

        $amenity = Amenity::create([
            'name' => 'Finnish sauna',
            'name_translations' => ['nl' => 'Finse sauna'],
        ]);

        $property->amenities()->attach($amenity);

        $this->withSession(['locale' => 'nl'])
            ->get('/saunahuis')
            ->assertOk()
            ->assertSee('Finse sauna')
            ->assertDontSee('Finnish sauna');
    }
}
