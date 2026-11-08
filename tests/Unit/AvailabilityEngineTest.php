<?php

namespace Tests\Unit;

use App\Engines\AvailabilityEngine;
use App\Engines\CalendarEngine;
use App\Enums\CalendarBlockType;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityEngineTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityEngine $engine;

    private CalendarEngine $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AvailabilityEngine;
        $this->calendar = new CalendarEngine;
    }

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Testhuis',
            'slug' => 'testhuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 6,
            'active' => true,
        ], $overrides));
    }

    private function check(Property $property, string $arrival, string $departure)
    {
        return $this->engine->check(
            $property,
            CarbonImmutable::parse($arrival),
            CarbonImmutable::parse($departure),
        );
    }

    public function test_available_without_blocks_or_rules(): void
    {
        $result = $this->check($this->makeProperty(), '2026-03-02', '2026-03-05');

        $this->assertTrue($result->available);
        $this->assertSame([], $result->reasons);
    }

    public function test_inactive_accommodation_is_not_bookable(): void
    {
        $result = $this->check($this->makeProperty(['active' => false]), '2026-03-02', '2026-03-05');

        $this->assertFalse($result->available);
    }

    public function test_overlapping_block_makes_unavailable(): void
    {
        $property = $this->makeProperty();
        $this->calendar->block(
            $property,
            CarbonImmutable::parse('2026-03-03'),
            CarbonImmutable::parse('2026-03-04'),
        );

        $this->assertFalse($this->check($property, '2026-03-02', '2026-03-05')->available);
    }

    public function test_block_that_borders_on_stay_disturbs_not(): void
    {
        $property = $this->makeProperty();
        // Nights of 5 and 6 March blocked; stay 2 to 5 March uses nights 2-4.
        $this->calendar->block(
            $property,
            CarbonImmutable::parse('2026-03-05'),
            CarbonImmutable::parse('2026-03-06'),
        );

        $this->assertTrue($this->check($property, '2026-03-02', '2026-03-05')->available);
        // And arriving on the day after the block works too.
        $this->assertTrue($this->check($property, '2026-03-07', '2026-03-09')->available);
    }

    public function test_released_block_makes_again_available(): void
    {
        $property = $this->makeProperty();
        $block = $this->calendar->block(
            $property,
            CarbonImmutable::parse('2026-03-03'),
            CarbonImmutable::parse('2026-03-04'),
            CalendarBlockType::Maintenance,
        );

        $this->assertFalse($this->check($property, '2026-03-02', '2026-03-05')->available);

        $this->calendar->release($block);

        $this->assertTrue($this->check($property, '2026-03-02', '2026-03-05')->available);
    }

    public function test_minimum_stay_rule(): void
    {
        $property = $this->makeProperty();
        $property->bookingRules()->create(['min_nights' => 3]);

        $this->assertFalse($this->check($property, '2026-03-02', '2026-03-04')->available);
        $this->assertTrue($this->check($property, '2026-03-02', '2026-03-05')->available);
    }

    public function test_maximum_stay_rule(): void
    {
        $property = $this->makeProperty();
        $property->bookingRules()->create(['min_nights' => 1, 'max_nights' => 7]);

        $this->assertTrue($this->check($property, '2026-03-02', '2026-03-09')->available);
        $this->assertFalse($this->check($property, '2026-03-02', '2026-03-10')->available);
    }

    public function test_maximum_rental_period_applies_also_without_booking_rule(): void
    {
        $property = $this->makeProperty(['max_stay_nights' => 28]);

        $this->assertTrue($this->check($property, '2026-03-02', '2026-03-30')->available); // 28 nights
        $this->assertFalse($this->check($property, '2026-03-02', '2026-03-31')->available); // 29 nights
    }

    public function test_arrival_only_on_allowed_days(): void
    {
        $property = $this->makeProperty();
        $property->bookingRules()->create([
            'min_nights' => 1,
            'arrival_days' => ['5', '6'], // only Friday and Saturday (as strings, the way forms store them)
        ]);

        // 6 March 2026 is a Friday, 4 March a Wednesday.
        $this->assertTrue($this->check($property, '2026-03-06', '2026-03-09')->available);
        $this->assertFalse($this->check($property, '2026-03-04', '2026-03-07')->available);
    }

    public function test_departure_only_on_allowed_days(): void
    {
        $property = $this->makeProperty();
        $property->bookingRules()->create([
            'min_nights' => 1,
            'departure_days' => [1, 5], // only Monday and Friday
        ]);

        // 9 March 2026 is a Monday, 8 March a Sunday.
        $this->assertTrue($this->check($property, '2026-03-06', '2026-03-09')->available);
        $this->assertFalse($this->check($property, '2026-03-06', '2026-03-08')->available);
    }

    public function test_rule_with_period_applies_only_inside_that_period(): void
    {
        $property = $this->makeProperty();
        $property->bookingRules()->create([
            'name' => 'Hoogseizoen: minimaal een week',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-31',
            'min_nights' => 7,
        ]);

        // Arrival in high season: 3 nights is not allowed.
        $this->assertFalse($this->check($property, '2026-07-06', '2026-07-09')->available);
        // Same length of stay outside the season: fine.
        $this->assertTrue($this->check($property, '2026-03-02', '2026-03-05')->available);
    }

    public function test_external_sync_is_idempotent_and_cleans_up_disappeared_bookings_on(): void
    {
        $property = $this->makeProperty();

        $entry = fn (string $uid, string $start, string $end) => [
            'external_uid' => $uid,
            'start_date' => CarbonImmutable::parse($start),
            'end_date' => CarbonImmutable::parse($end),
        ];

        $this->calendar->syncExternal($property, 'airbnb-ical', [
            $entry('uid-1', '2026-04-01', '2026-04-04'),
            $entry('uid-2', '2026-04-10', '2026-04-12'),
        ]);

        $this->assertSame(2, $property->calendarBlocks()->count());
        $this->assertFalse($this->check($property, '2026-04-01', '2026-04-03')->available);

        // Second sync: uid-2 was cancelled on the platform, uid-1 stays.
        $this->calendar->syncExternal($property, 'airbnb-ical', [
            $entry('uid-1', '2026-04-01', '2026-04-04'),
        ]);

        $this->assertSame(1, $property->calendarBlocks()->count());
        $this->assertTrue($this->check($property, '2026-04-10', '2026-04-13')->available);
    }
}
