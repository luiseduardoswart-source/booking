<?php

namespace Tests\Feature;

use App\Engines\AvailabilityEngine;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StayRulesTest extends TestCase
{
    use RefreshDatabase;

    private function property(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Regelhuis', 'slug' => 'regelhuis-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ], $overrides));
    }

    public function test_minimum_stay_blocks_to_short_bookings(): void
    {
        $property = $this->property(['min_stay_nights' => 3]);
        $engine = app(AvailabilityEngine::class);

        $tomorrow = CarbonImmutable::tomorrow();

        $toShort = $engine->check($property, $tomorrow, $tomorrow->addDays(2));
        $this->assertFalse($toShort->available);
        $this->assertStringContainsString('3', implode(' ', $toShort->reasons));

        $this->assertTrue($engine->check($property, $tomorrow, $tomorrow->addDays(3))->available);
    }

    public function test_minimum_stay_falls_back_on_the_defaults(): void
    {
        Property::master()->update(['min_stay_nights' => 2]);
        $property = $this->property();

        $tomorrow = CarbonImmutable::tomorrow();

        $this->assertFalse(app(AvailabilityEngine::class)->check($property, $tomorrow, $tomorrow->addDay())->available);
    }

    public function test_booking_horizon_blocks_to_far_ahead_book(): void
    {
        $property = $this->property(['booking_window_months' => 3]);
        $engine = app(AvailabilityEngine::class);

        $toFar = CarbonImmutable::today()->addMonths(4);
        $result = $engine->check($property, $toFar, $toFar->addDays(3));

        $this->assertFalse($result->available);
        $this->assertStringContainsString('3', implode(' ', $result->reasons));

        $inside = CarbonImmutable::today()->addMonth();
        $this->assertTrue($engine->check($property, $inside, $inside->addDays(3))->available);
    }

    public function test_min_nights_map_combines_base_and_rules(): void
    {
        $property = $this->property(['min_stay_nights' => 2]);
        $property->bookingRules()->create([
            'min_nights' => 5,
            'start_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(20)->toDateString(),
        ]);

        $map = app(AvailabilityEngine::class)->minNightsMap(
            $property,
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(30),
        );

        $this->assertSame(2, $map[CarbonImmutable::today()->addDays(5)->toDateString()]);
        $this->assertSame(5, $map[CarbonImmutable::today()->addDays(15)->toDateString()]);
        $this->assertSame(2, $map[CarbonImmutable::today()->addDays(25)->toDateString()]);
    }

    public function test_calendar_api_sends_min_nights_and_horizon_along(): void
    {
        $property = $this->property(['min_stay_nights' => 3, 'booking_window_months' => 6]);

        $this->get("/api/v1/properties/{$property->id}/unavailable-dates")
            ->assertOk()
            ->assertJsonPath('max_date', CarbonImmutable::today()->addMonths(6)->toDateString())
            ->assertJsonPath('min_nights.'.CarbonImmutable::today()->addDay()->toDateString(), 3);
    }
}
