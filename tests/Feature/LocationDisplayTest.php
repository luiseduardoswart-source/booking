<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Where an accommodation is: shown approximately (a circle whose centre is
 * not the real spot) or exactly (a pin on the address), the same on the
 * page, the overview map and the API. Coordinates outside the world get a
 * clear message instead of a failed save.
 */
class LocationDisplayTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 10.4090553;

    private const LNG = -75.5514039;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function accommodation(array $extra = []): Property
    {
        static $counter = 0;
        $counter++;

        return Property::create([
            'name' => 'Casa Cartagena', 'slug' => "casa-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'address' => 'Calle del Cuartel 36', 'city' => 'Cartagena', 'country' => 'CO',
            'latitude' => self::LAT, 'longitude' => self::LNG, ...$extra,
        ]);
    }

    /** Distance in metres between two points (good enough at this scale). */
    private function metres(array $a, float $lat, float $lng): float
    {
        $dLat = ($a['lat'] - $lat) * 111320;
        $dLng = ($a['lng'] - $lng) * 111320 * cos(deg2rad($lat));

        return sqrt($dLat ** 2 + $dLng ** 2);
    }

    public function test_a_longitude_without_decimal_point_gets_a_clear_message(): void
    {
        $accommodation = $this->accommodation(['latitude' => null, 'longitude' => null]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            // Empty fields look empty: no grey example number that looks filled in.
            ->assertDontSeeHtml('placeholder="10.4090553"')
            ->assertDontSeeHtml('placeholder="-75.5514039"')
            ->assertSee(__('With a decimal point, as Google Maps gives it'))
            ->assertDontSee('52.3676')
            ->fillForm(['latitude' => '10.4090553', 'longitude' => '-755514039'])
            ->call('save')
            ->assertHasFormErrors(['longitude'])
            ->assertSee(__('A longitude lies between -180 and 180. Did you forget the decimal point?'))
            ->fillForm(['longitude' => '-75.5514039'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(self::LNG, (float) $accommodation->refresh()->longitude, 0.0000001);
    }

    public function test_approximately_the_circle_is_near_but_not_on_the_spot(): void
    {
        $accommodation = $this->accommodation();
        $spot = $accommodation->publicCoordinates();

        $distance = $this->metres($spot, self::LAT, self::LNG);
        $this->assertGreaterThan(0.3 * Property::LOCATION_SHIFT - 1, $distance);
        $this->assertLessThanOrEqual(Property::LOCATION_SHIFT + 1, $distance);
        // Always the same spot for this accommodation, so the circle does not jump.
        $this->assertSame($spot, $accommodation->refresh()->publicCoordinates());

        $this->get(route('properties.show', $accommodation->slug))->assertOk()
            ->assertSee('data-lat="'.$spot['lat'].'"', false)
            ->assertSee('data-exact="0"', false)
            ->assertDontSee((string) self::LAT)
            ->assertDontSee('Calle del Cuartel')
            ->assertSee(__('You will receive the exact location after booking.'));
    }

    public function test_exactly_the_pin_is_on_the_address(): void
    {
        $accommodation = $this->accommodation(['location_display' => Property::LOCATION_EXACT]);

        $this->assertSame(['lat' => self::LAT, 'lng' => self::LNG], $accommodation->publicCoordinates());

        $this->get(route('properties.show', $accommodation->slug))->assertOk()
            ->assertSee('data-lat="'.self::LAT.'"', false)
            ->assertSee('data-exact="1"', false)
            ->assertSee('Calle del Cuartel 36')
            ->assertDontSee(__('You will receive the exact location after booking.'));
    }

    public function test_the_overview_map_and_the_api_do_not_give_away_the_spot(): void
    {
        $approximate = $this->accommodation();
        $exact = $this->accommodation(['location_display' => Property::LOCATION_EXACT, 'latitude' => 10.42, 'longitude' => -75.54]);

        $this->get('/')->assertOk()->assertDontSee((string) self::LAT);

        $this->getJson("/api/v1/properties/{$approximate->id}")->assertOk()
            ->assertJsonPath('data.latitude', $approximate->publicCoordinates()['lat']);
        $this->assertNotEquals(self::LAT, $approximate->publicCoordinates()['lat']);

        $this->getJson("/api/v1/properties/{$exact->id}")->assertOk()
            ->assertJsonPath('data.latitude', 10.42);
    }

    /** The overview map data on the homepage, by accommodation name. */
    private function overviewPins(): array
    {
        preg_match('/<script type="application\/json" id="explore-map-data">(.*?)<\/script>/s', $this->get('/')->getContent(), $match);

        return collect(json_decode(html_entity_decode($match[1] ?? '[]'), true))->keyBy('name')->all();
    }

    public function test_the_two_maps_have_their_own_choice(): void
    {
        // The overview map only appears with more than one accommodation.
        $this->accommodation(['name' => 'Casa Getsemaní']);

        // Own page: no map. Overview: exactly.
        $accommodation = $this->accommodation([
            'location_display' => Property::LOCATION_NONE,
            'overview_location_display' => Property::LOCATION_EXACT,
        ]);

        $this->get(route('properties.show', $accommodation->slug))->assertOk()
            ->assertDontSee('id="map"', false)
            ->assertDontSee(__('View on the map'));
        $this->getJson("/api/v1/properties/{$accommodation->id}")->assertJsonPath('data.latitude', null);

        $this->assertSame(self::LAT, $this->overviewPins()['Casa Cartagena']['lat']);

        // Own page: exactly. Overview: not on the map.
        $accommodation->update(['location_display' => Property::LOCATION_EXACT, 'overview_location_display' => Property::LOCATION_NONE]);

        $this->get(route('properties.show', $accommodation->slug))->assertOk()->assertSee('data-exact="1"', false);
        $this->assertArrayNotHasKey('Casa Cartagena', $this->overviewPins());
    }

    public function test_both_choices_are_saved_from_the_admin(): void
    {
        $accommodation = $this->accommodation();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['location_display' => Property::LOCATION_NONE, 'overview_location_display' => Property::LOCATION_EXACT])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame(Property::LOCATION_NONE, $accommodation->location_display);
        $this->assertSame(Property::LOCATION_EXACT, $accommodation->overview_location_display);
    }

    public function test_without_coordinates_there_is_no_map(): void
    {
        $accommodation = $this->accommodation(['latitude' => null, 'longitude' => null]);

        $this->assertNull($accommodation->publicCoordinates());
        $this->get(route('properties.show', $accommodation->slug))->assertOk()->assertDontSee('id="map"', false);
    }
}
