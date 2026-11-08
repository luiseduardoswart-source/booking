<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Property;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What a guest picks in the form (dates, guests, pets) is checked again
 * by the server when booking. Also if someone changes the values outside the
 * form, for example with the developer tools (F12).
 */
class BookingChoicesManipulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->app->instance(PaymentProvider::class, new FakeProvider);

        Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'max_stay_nights' => 28, 'pets_allowed' => false,
        ]);
    }

    private function book(array $fields)
    {
        return $this->post('/strandhuis/book', [
            'arrival' => today()->addDays(10)->toDateString(),
            'departure' => today()->addDays(13)->toDateString(),
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
            ...$fields,
        ]);
    }

    public function test_longer_then_the_maximum_rental_period_can_not(): void
    {
        $this->book(['departure' => today()->addDays(40)->toDateString()]) // 30 nights, max 28
            ->assertSessionHasErrors('availability');

        $this->assertSame(0, Booking::query()->count());

        // 28 nights is allowed.
        $this->book(['departure' => today()->addDays(38)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(28, Booking::query()->sole()->nights());
    }

    public function test_more_guests_then_allowed_can_not(): void
    {
        $this->book(['guests' => 6])->assertSessionHasErrors('availability');
        $this->book(['adults' => 3, 'children' => 2])->assertSessionHasErrors('availability');

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_the_count_guests_follows_off_adults_and_children(): void
    {
        // A separate, larger "guests" field changes nothing.
        $this->book(['adults' => 2, 'children' => 1, 'guests' => 1])->assertSessionHasNoErrors();

        $this->assertSame(3, Booking::query()->sole()->guests);
    }

    public function test_pets_where_they_not_may_can_not(): void
    {
        $this->book(['adults' => 2, 'pets' => 2])->assertSessionHasErrors('pets');

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_a_arrival_in_the_past_can_not(): void
    {
        $this->book([
            'arrival' => today()->subDay()->toDateString(),
            'departure' => today()->addDays(2)->toDateString(),
        ])->assertSessionHasErrors(['arrival' => __('The arrival date is in the past.')]);

        $this->assertSame(0, Booking::query()->count());

        // Arriving today is allowed.
        $this->book([
            'arrival' => today()->toDateString(),
            'departure' => today()->addDays(2)->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    public function test_the_price_calculation_rejects_same_choices(): void
    {
        $url = fn (array $choices) => route('api.properties.quote', ['property' => Property::query()->sole(), ...$choices]);

        $this->getJson($url(['arrival' => today()->addDays(10)->toDateString(), 'departure' => today()->addDays(40)->toDateString(), 'guests' => 2]))
            ->assertJson(['available' => false]);
        $this->getJson($url(['arrival' => today()->subDays(2)->toDateString(), 'departure' => today()->addDay()->toDateString(), 'guests' => 2]))
            ->assertUnprocessable()->assertJsonValidationErrors('arrival');
        $this->getJson($url(['arrival' => today()->addDays(10)->toDateString(), 'departure' => today()->addDays(13)->toDateString(), 'guests' => 2, 'pets' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('pets');
    }
}
