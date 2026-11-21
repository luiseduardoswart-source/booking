<?php

namespace Tests\Feature;

use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Engines\PricingEngine;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Property;
use App\Models\RateSeason;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A stay is a number of calendar nights. A date sent with a time zone behind
 * it ("2027-03-08T00:00:00+14:00") is another moment but the same calendar
 * day: counted as a moment, the server priced fewer nights than it stored.
 * Such dates are refused, and the engines count calendar days whatever they
 * are handed.
 */
class StayDatesManipulationTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->app->instance(PaymentProvider::class, new FakeProvider);
        $this->travelTo('2027-01-15 12:00:00');

        // 100 a night, 50 cleaning; 10 March is a peak night of 500.
        $this->property = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'cleaning_fee_cents' => 5000,
        ]);
        RateSeason::create(['property_id' => $this->property->id, 'name' => 'Peak', 'start_date' => '2027-03-10', 'end_date' => '2027-03-10', 'price_cents' => 50000]);
    }

    private function book(string $arrival, string $departure)
    {
        return $this->post('/strandhuis/book', [
            'arrival' => $arrival, 'departure' => $departure, 'guests' => 2,
            'name' => 'Jan Jansen', 'email' => 'jan@example.com', 'terms' => '1',
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function datesWithATimeZone(): array
    {
        return [
            'seven nights for the price of five' => ['2027-03-01T00:00:00-12:00', '2027-03-08T00:00:00+14:00'],
            'a night for the cleaning fee only' => ['2027-03-01', '2027-03-02T00:00:00+01:00'],
            'a peak night for the base price' => ['2027-03-10T00:00:00-01:00', '2027-03-11T00:00:00-01:00'],
            'a time behind the date' => ['2027-03-01 00:00:00', '2027-03-08 23:59:59'],
        ];
    }

    #[DataProvider('datesWithATimeZone')]
    public function test_the_site_refuses_a_date_that_is_more_than_a_calendar_day(string $arrival, string $departure): void
    {
        $this->book($arrival, $departure)->assertSessionHasErrors();
        $this->assertSame(0, Booking::count());

        $this->get('/strandhuis/book?'.http_build_query(['arrival' => $arrival, 'departure' => $departure, 'guests' => 2]))
            ->assertSessionHasErrors();
    }

    #[DataProvider('datesWithATimeZone')]
    public function test_the_price_request_and_the_api_refuse_it_too(string $arrival, string $departure): void
    {
        $this->getJson('/api/v1/properties/'.$this->property->id.'/quote?'.http_build_query(['arrival' => $arrival, 'departure' => $departure, 'guests' => 2]))
            ->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Administrator]));
        $this->postJson('/api/v1/bookings', [
            'property_id' => $this->property->id, 'arrival' => $arrival, 'departure' => $departure, 'guests' => 2,
            'guest' => ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        ])->assertStatus(422);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_plain_date_still_books(): void
    {
        $this->book('2027-03-01', '2027-03-08')->assertSessionHasNoErrors();

        $booking = Booking::sole();
        $this->assertSame(7, $booking->nights());
        $this->assertSame(7 * 10000 + 5000, $booking->total_cents);
    }

    /**
     * Second lock on the door: whatever a caller hands the engines (admin,
     * API, AI connection), they price and check the calendar nights that are
     * stored.
     */
    public function test_the_engines_count_calendar_nights_whatever_the_time_zone(): void
    {
        $from = CarbonImmutable::parse('2027-03-01T00:00:00-12:00');
        $until = CarbonImmutable::parse('2027-03-08T00:00:00+14:00');

        $quote = app(PricingEngine::class)->quote($this->property, $from, $until, 2);
        $this->assertSame(7, $quote->nights);
        $this->assertSame(7 * 10000 + 5000, $quote->totalCents);

        // The peak night keeps its price.
        $peak = app(PricingEngine::class)->quote($this->property, CarbonImmutable::parse('2027-03-10T00:00:00-01:00'), CarbonImmutable::parse('2027-03-11T00:00:00+14:00'), 2);
        $this->assertSame(1, $peak->nights);
        $this->assertSame(50000 + 5000, $peak->totalCents);

        // A minimum stay counts the same nights.
        $this->property->update(['min_stay_nights' => 7]);
        $this->assertTrue(app(AvailabilityEngine::class)->check($this->property->fresh(), $from, $until)->available);

        $booking = app(BookingEngine::class)->create($this->property->fresh(), $from, $until, 2, ['name' => 'Jan Jansen', 'email' => 'jan@example.com']);
        $this->assertSame('2027-03-01', $booking->arrival_date->toDateString());
        $this->assertSame('2027-03-08', $booking->departure_date->toDateString());
        $this->assertSame($booking->nights(), $booking->price_snapshot['nights']);
        $this->assertSame(7 * 10000 + 5000, $booking->total_cents);
    }

    public function test_a_guest_cannot_book_longer_than_a_year_or_years_ahead(): void
    {
        // A hundred years would also be more than a payment provider accepts.
        $this->book('2027-03-01', '2127-03-01')->assertSessionHasErrors('departure');
        $this->book('2027-03-01', '2028-03-02')->assertSessionHasErrors('departure'); // 367 nights
        $this->book('2031-03-01', '2031-03-08')->assertSessionHasErrors('departure'); // over three years ahead

        $this->get('/strandhuis/book?arrival=2027-03-01&departure=2127-03-01&guests=2')->assertSessionHasErrors('departure');

        $this->assertSame(0, Booking::count());
    }
}
