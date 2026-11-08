<?php

namespace Tests\Feature;

use App\Models\NightlyRate;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyOverridesTest extends TestCase
{
    use RefreshDatabase;

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Overridehuis',
            'slug' => 'overridehuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ], $overrides));
    }

    public function test_id_required_falls_back_on_the_default(): void
    {
        Property::master()->update(['id_required' => true]);
        $property = $this->makeProperty(); // eigen keuze null

        $this->assertTrue($property->idRequired());

        Property::master()->update(['id_required' => false]);
        $this->assertFalse($property->fresh()->idRequired());
    }

    public function test_id_required_per_accommodation_overrides_the_default(): void
    {
        Property::master()->update(['id_required' => false]);

        $on = $this->makeProperty(['id_required' => true]);
        $off = $this->makeProperty(['id_required' => false]);

        $this->assertTrue($on->idRequired());   // ondanks standaard uit
        $this->assertFalse($off->idRequired());
    }

    public function test_prices_in_calendar_per_accommodation_overrides_the_default(): void
    {
        $on = $this->makeProperty(['prices_in_calendar' => true]);
        $this->assertTrue($on->pricesInCalendar());

        $follows = $this->makeProperty(); // null → volgt de standaard (uit)
        $this->assertFalse($follows->pricesInCalendar());
    }

    public function test_endpoint_shows_prices_as_accommodation_the_switches_on_despite_general_off(): void
    {
        $property = $this->makeProperty(['prices_in_calendar' => true]);
        NightlyRate::create(['property_id' => $property->id, 'date' => today()->addDays(2)->toDateString(), 'price_cents' => 15000, 'source' => 'manual']);

        $this->getJson("/api/v1/properties/{$property->id}/unavailable-dates")
            ->assertOk()
            ->assertJsonStructure(['dates', 'prices']);
    }
}
