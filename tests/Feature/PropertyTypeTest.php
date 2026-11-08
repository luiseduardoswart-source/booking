<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_types_exist_after_the_migration(): void
    {
        $slugs = PropertyType::query()->pluck('slug')->all();

        foreach (['holiday_home', 'apartment', 'room'] as $slug) {
            $this->assertContains($slug, $slugs);
        }
    }

    public function test_slug_is_automatic_generated(): void
    {
        $type = PropertyType::create(['name' => 'Boomhut Deluxe']);

        $this->assertSame('boomhut-deluxe', $type->slug);
    }

    public function test_type_name_used_own_translation_with_fallback(): void
    {
        PropertyType::create(['name' => 'Treehouse', 'name_translations' => ['nl' => 'Boomhut']]);

        $property = Property::create([
            'name' => 'Hoog huis', 'slug' => 'hoog-huis', 'type' => 'treehouse',
            'max_guests' => 2, 'active' => true,
        ]);

        app()->setLocale('nl');
        $this->assertSame('Boomhut', $property->typeName());

        // Without a translation the English name, the source language.
        app()->setLocale('fr');
        $this->assertSame('Treehouse', $property->typeName());
    }

    public function test_default_type_stays_translated_via_the_language_files(): void
    {
        $property = Property::create([
            'name' => 'Huis', 'slug' => 'house', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);

        app()->setLocale('en');
        $this->assertSame('Holiday home', $property->typeName());

        app()->setLocale('nl');
        $this->assertSame('Vakantiehuis', $property->typeName());
    }

    public function test_deleted_type_breaks_the_site_not(): void
    {
        $property = Property::create([
            'name' => 'Huis', 'slug' => 'raar-huis', 'type' => 'iglo',
            'max_guests' => 2, 'active' => true,
        ]);

        $this->assertSame('Iglo', $property->typeName());
        $this->get('/raar-huis')->assertOk();
    }

    public function test_deleted_default_type_keeps_are_translated_name(): void
    {
        PropertyType::query()->where('slug', 'room')->delete();
        $property = Property::create([
            'name' => 'Kamer', 'slug' => 'small_room', 'type' => 'room',
            'max_guests' => 2, 'active' => true,
        ]);

        app()->setLocale('nl');
        $this->assertSame('Kamer', $property->typeName());

        app()->setLocale('de');
        $this->assertSame('Zimmer', $property->typeName());
    }

    public function test_the_types_are_english_and_accommodations_keep_their_type(): void
    {
        // The state before this change: Dutch names.
        PropertyType::query()->delete();
        $holidayHome = PropertyType::create(['slug' => 'holiday_home', 'name' => 'Vakantiehuis']);
        $treehouse = PropertyType::create(['slug' => 'treehouse', 'name' => 'Boomhut', 'name_translations' => ['en' => 'Treehouse', 'de' => 'Baumhaus']]);
        $houseboat = PropertyType::create(['slug' => 'houseboat', 'name' => 'Woonboot']);
        $property = Property::create([
            'name' => 'Huis', 'slug' => 'house', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);

        $migration = require database_path('migrations/2026_11_03_150000_types_english_as_source_language.php');
        $migration->up();

        $this->assertSame('Holiday home', $holidayHome->fresh()->name);
        $this->assertNull($holidayHome->fresh()->name_translations);
        $this->assertSame('Treehouse', $treehouse->fresh()->name);
        $this->assertSame(['de' => 'Baumhaus', 'nl' => 'Boomhut'], $treehouse->fresh()->name_translations);
        $this->assertSame('Woonboot', $houseboat->fresh()->name);
        $this->assertSame(['nl' => 'Woonboot'], $houseboat->fresh()->name_translations);
        $this->assertSame('holiday_home', $property->fresh()->type);

        app()->setLocale('nl');
        $this->assertSame('Vakantiehuis', $property->fresh()->typeName());

        $migration->down();

        $this->assertSame('Vakantiehuis', $holidayHome->fresh()->name);
        $this->assertSame('Boomhut', $treehouse->fresh()->name);
        $this->assertSame(['de' => 'Baumhaus', 'en' => 'Treehouse'], $treehouse->fresh()->name_translations);
    }

    public function test_admin_shows_the_current_view_as_example_per_language(): void
    {
        // Default type: the built-in translation is visible as an example,
        // even though the translation field is empty.
        $type = PropertyType::query()->where('slug', 'holiday_home')->first();

        $this->assertSame('Vakantiehuis', \Illuminate\Support\Facades\Lang::get($type->name, [], 'nl'));
        $this->assertSame('Ferienhaus', \Illuminate\Support\Facades\Lang::get($type->name, [], 'de'));

        // Own type without a built-in translation: then the English name.
        $custom = PropertyType::create(['name' => 'Treehouse']);
        $this->assertSame('Treehouse', \Illuminate\Support\Facades\Lang::get($custom->name, [], 'nl'));
    }

    public function test_type_can_from_the_accommodation_form_are_created(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Administrator]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\Properties\Pages\CreateProperty::class)
            ->callAction(
                \Filament\Actions\Testing\TestAction::make('createOption')->schemaComponent('type'),
                data: ['name' => 'Houseboat'],
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('property_types', ['slug' => 'houseboat', 'name' => 'Houseboat']);
    }

    public function test_new_type_appears_direct_on_the_site(): void
    {
        PropertyType::create(['name' => 'Treehouse', 'name_translations' => ['nl' => 'Boomhut']]);

        $property = Property::create([
            'name' => 'Hoog huis', 'slug' => 'hoog-huis', 'type' => 'treehouse',
            'max_guests' => 2, 'active' => true,
        ]);

        $this->withSession(['locale' => 'nl'])
            ->get('/hoog-huis')
            ->assertOk()
            ->assertSee('Boomhut');
    }
}
