<?php

namespace Tests\Unit;

use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Engines\Exceptions\InvalidStatusTransitionException;
use App\Engines\Exceptions\UnavailableForBookingException;
use App\Engines\PricingEngine;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingEngineTest extends TestCase
{
    use RefreshDatabase;

    private BookingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new BookingEngine(new AvailabilityEngine, new PricingEngine);
    }

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Testhuis',
            'slug' => 'testhuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 6,
            'active' => true,
            'base_price_cents' => 10000,
            'cleaning_fee_cents' => 5000,
            'deposit_cents' => 20000,
        ], $overrides));
    }

    private function book(Property $property, string $arrival, string $departure, int $guests = 2): Booking
    {
        return $this->engine->create(
            $property,
            CarbonImmutable::parse($arrival),
            CarbonImmutable::parse($departure),
            $guests,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
    }

    public function test_booking_is_created_with_number_status_and_snapshot(): void
    {
        $booking = $this->book($this->makeProperty(), '2026-03-02', '2026-03-05');

        $this->assertMatchesRegularExpression('/^BK-\d{4}-\d{4}$/', $booking->booking_number);
        $this->assertSame(BookingStatus::New, $booking->status);
        $this->assertSame(35000, $booking->total_cents); // 3 × €100 + €50 cleaning
        $this->assertSame(20000, $booking->deposit_cents);
        $this->assertSame(3, $booking->nights());
        $this->assertNotEmpty($booking->price_snapshot['lines']);
    }

    public function test_overlapping_booking_is_rejected(): void
    {
        $property = $this->makeProperty();
        $this->book($property, '2026-03-02', '2026-03-05');

        $this->expectException(UnavailableForBookingException::class);

        $this->book($property, '2026-03-04', '2026-03-07');
    }

    public function test_adjoining_booking_is_allowed(): void
    {
        $property = $this->makeProperty();
        $this->book($property, '2026-03-02', '2026-03-05');

        // Arrival on the departure day of the previous guest: no conflict.
        $booking = $this->book($property, '2026-03-05', '2026-03-08');

        $this->assertSame(BookingStatus::New, $booking->status);
    }

    public function test_cancelled_booking_frees_the_dates(): void
    {
        $property = $this->makeProperty();
        $booking = $this->book($property, '2026-03-02', '2026-03-05');

        $this->engine->cancel($booking, 'Gast heeft afgezegd');

        $rebooking = $this->book($property, '2026-03-02', '2026-03-05');

        $this->assertSame(BookingStatus::New, $rebooking->status);
    }

    public function test_booking_blocks_the_availability(): void
    {
        $property = $this->makeProperty();
        $this->book($property, '2026-03-02', '2026-03-05');

        $availability = new AvailabilityEngine;
        $result = $availability->check(
            $property,
            CarbonImmutable::parse('2026-03-03'),
            CarbonImmutable::parse('2026-03-06'),
        );

        $this->assertFalse($result->available);
    }

    public function test_price_snapshot_does_not_change_on_a_later_price_change(): void
    {
        $property = $this->makeProperty();
        $booking = $this->book($property, '2026-03-02', '2026-03-05');

        $property->update(['base_price_cents' => 99900]);

        $booking->refresh();
        $this->assertSame(35000, $booking->total_cents);
        $this->assertSame(35000, $booking->price_snapshot['total_cents']);
    }

    public function test_valid_status_transitions(): void
    {
        $booking = $this->book($this->makeProperty(), '2026-03-02', '2026-03-05');

        $this->engine->transition($booking, BookingStatus::Confirmed);
        $this->engine->transition($booking, BookingStatus::Paid);
        $this->engine->transition($booking, BookingStatus::Completed);

        $this->assertSame(BookingStatus::Completed, $booking->status);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $booking = $this->book($this->makeProperty(), '2026-03-02', '2026-03-05');

        $this->engine->cancel($booking);

        $this->expectException(InvalidStatusTransitionException::class);

        // Cancelled is a final status: going back to paid is not possible.
        $this->engine->transition($booking, BookingStatus::Paid);
    }

    public function test_booking_respects_booking_rules(): void
    {
        $property = $this->makeProperty();
        $property->bookingRules()->create(['min_nights' => 7]);

        $this->expectException(UnavailableForBookingException::class);

        $this->book($property, '2026-03-02', '2026-03-05');
    }

    public function test_two_simultaneous_attempts_can_never_both_succeed(): void
    {
        // Simulates the race: attempt B does its availability check AFTER
        // attempt A has already booked, but on a stale property object.
        // The re-check inside the transaction (on the locked, fresh row)
        // must still refuse B.
        $property = $this->makeProperty();
        $staleProperty = Property::find($property->id); // "second visitor"

        $this->book($property, '2026-03-02', '2026-03-05');

        $this->expectException(UnavailableForBookingException::class);

        $this->book($staleProperty, '2026-03-02', '2026-03-05');
    }
}
