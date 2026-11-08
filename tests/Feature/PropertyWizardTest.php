<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Models\Property;
use App\Models\User;
use App\Services\Importing\FakeListingImporter;
use App\Services\Importing\ListingDraft;
use App\Services\Importing\ListingImportProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PropertyWizardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_quick_wizard_makes_a_accommodation_with_automatic_slug(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateProperty::class)
            ->fillForm([
                'name' => 'Zonnig Appartement',
                'type' => 'apartment',
                'max_guests' => 4,
                'city' => 'Haarlem',
                'base_price_cents' => 125, // €125 → 12500 cents via euroInput
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $property = Property::where('name', 'Zonnig Appartement')->first();
        $this->assertNotNull($property);
        $this->assertSame('zonnig-appartement', $property->slug);
        $this->assertSame('Haarlem', $property->city);
        $this->assertSame(12500, $property->base_price_cents);
        $this->assertTrue((bool) $property->active);
    }

    public function test_fetch_button_fills_the_wizard_fields_from_a_link(): void
    {
        $fake = new FakeListingImporter;
        $fake->draft = new ListingDraft(
            name: 'Grachtenpand',
            shortDescription: 'Aan de gracht',
            city: 'Amsterdam',
            maxGuests: 6,
            basePriceCents: 20000, // €200
        );
        $this->app->instance(ListingImportProvider::class, $fake);

        $component = Livewire::actingAs($this->admin())
            ->test(CreateProperty::class)
            ->set('data.import_url', 'https://example.test/listing/9')
            ->callFormComponentAction('import_url', 'fetch');

        $component
            ->assertFormSet(['name' => 'Grachtenpand', 'city' => 'Amsterdam', 'max_guests' => 6])
            ->assertFormSet(['base_price_cents' => 200.0]); // euroInput shows euros

        // And saving afterwards yields a property with that data.
        $component->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('properties', ['name' => 'Grachtenpand', 'city' => 'Amsterdam', 'base_price_cents' => 20000]);
    }

    public function test_double_name_gets_unique_slug_in_the_wizard(): void
    {
        Property::create(['name' => 'Zeezicht', 'slug' => 'zeezicht', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true]);

        Livewire::actingAs($this->admin())
            ->test(CreateProperty::class)
            ->fillForm([
                'name' => 'Zeezicht',
                'type' => 'holiday_home',
                'max_guests' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('properties', ['name' => 'Zeezicht', 'slug' => 'zeezicht-2']);
    }
}
