<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\CalendarEngine;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UnavailableDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_gives_occupied_nights_of_bookings_and_blocks(): void
    {
        Mail::fake();

        $property = Property::create([
            'name' => 'Testhuis',
            'slug' => 'testhuis',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);

        // Booking 2 to 5 March: nights of 2, 3 and 4 March occupied.
        app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'Jan', 'email' => 'jan@example.com'],
        );

        // Block: nights of 10 and 11 March.
        app(CalendarEngine::class)->block(
            $property,
            CarbonImmutable::parse('2026-03-10'),
            CarbonImmutable::parse('2026-03-11'),
        );

        $response = $this->getJson("/api/v1/properties/{$property->id}/unavailable-dates?from=2026-03-01&to=2026-03-31");

        $response->assertOk()
            ->assertJsonPath('dates', ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-10', '2026-03-11']);

        // The departure day (5 March) is not an occupied night — a new guest can arrive then.
        $this->assertNotContains('2026-03-05', $response->json('dates'));
    }

    public function test_calendar_popover_is_on_the_detail_page(): void
    {
        $property = Property::create([
            'name' => 'Testhuis',
            'slug' => 'testhuis',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
        ]);

        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('calendar-popover', false)
            ->assertSee('unavailable-dates', false);
    }
}
