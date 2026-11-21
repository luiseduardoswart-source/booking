<?php

namespace Tests\Feature;

use App\Engines\PricingEngine;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\Property;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Services\ExchangeRates;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * An accommodation in another currency than the default takes over amounts
 * at the exchange rate. When the rates cannot be fetched, the number was used
 * as it is: 100 euros became 100 pesos. Now the last known rates are used,
 * and with no rate at all no price is made.
 */
class ExchangeRateOutageTest extends TestCase
{
    use RefreshDatabase;

    /** What the rate source answers: a list of rates, or null when it is down. */
    private ?array $rates = ['USD' => 1.10, 'COP' => 4500.0];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->app->instance(PaymentProvider::class, new FakeProvider);
        $this->travelTo('2027-01-15 12:00:00');

        Http::fake([
            'open.er-api.com/*' => fn () => $this->rates === null
                ? Http::response(null, 500)
                : Http::response(['result' => 'success', 'rates' => $this->rates]),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ]);

        // The default in euros: 100 a night, 50 cleaning.
        Property::master()->update(['currency' => 'EUR', 'base_price_cents' => 10000, 'cleaning_fee_cents' => 5000]);
    }

    /** In pesos, with every amount taken over from the default. */
    private function casa(): Property
    {
        return Property::create([
            'name' => 'Casa', 'slug' => 'casa', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'currency' => 'COP',
        ]);
    }

    private function quote(Property $property, ?Coupon $coupon = null): int
    {
        return app(PricingEngine::class)
            ->quote($property, CarbonImmutable::parse('2027-03-01'), CarbonImmutable::parse('2027-03-03'), 2, 0, $coupon)
            ->totalCents;
    }

    /** The 12 hours a fetched list is kept are over. */
    private function theRatesExpire(): void
    {
        Cache::forget('exchange_rates.eur.v2');
    }

    public function test_when_the_rates_cannot_be_fetched_the_last_known_rates_are_used(): void
    {
        $casa = $this->casa();
        $this->assertSame((2 * 10000 + 5000) * 4500, $this->quote($casa)); // COP 1,125,000

        $this->rates = null;
        $this->theRatesExpire();

        $this->assertSame(4500.0, ExchangeRates::exchangeRate('COP'));
        $this->assertSame((2 * 10000 + 5000) * 4500, $this->quote($casa));
    }

    public function test_without_any_rate_no_price_is_made(): void
    {
        $this->rates = null;
        $casa = $this->casa();

        try {
            $this->quote($casa);
            $this->fail('A price was made without an exchange rate.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('The price cannot be calculated right now. Please try again in a few minutes.'), $e->getMessage());
        }

        // Not on the site and not through the price request either.
        $this->post('/casa/book', [
            'arrival' => '2027-03-01', 'departure' => '2027-03-03', 'guests' => 2,
            'name' => 'Jan Jansen', 'email' => 'jan@example.com', 'terms' => '1',
        ])->assertSessionHasErrors('availability');
        $this->assertSame(0, Booking::count());

        $this->getJson('/api/v1/properties/'.$casa->id.'/quote?arrival=2027-03-01&departure=2027-03-03&guests=2')
            ->assertOk()->assertJson(['available' => false]);
    }

    public function test_an_accommodation_in_the_default_currency_needs_no_rate(): void
    {
        $this->rates = null;
        $house = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true,
        ]);

        $this->assertSame(2 * 10000 + 5000, $this->quote($house));
    }

    public function test_a_fixed_coupon_in_another_currency_gives_nothing_away_without_a_rate(): void
    {
        // The default in pesos, the house in euros: 50,000 pesos off is about 11 euros.
        Property::master()->update(['currency' => 'COP', 'base_price_cents' => 45000000, 'cleaning_fee_cents' => 0]);
        Money::forgetsDefaultCurrency();
        $house = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true,
            'currency' => 'EUR', 'base_price_cents' => 10000, 'cleaning_fee_cents' => 0,
        ]);
        $coupon = Coupon::create(['code' => 'FIFTY', 'type' => Coupon::TYPE_FIXED, 'value' => 5000000]);

        $this->assertSame(2 * 10000 - 1111, $this->quote($house, $coupon));

        // The rates are gone and were never stored: no free stay, no price at all.
        \App\Models\Setting::query()->where('group', 'system')->delete();
        \App\Models\Setting::forgets();
        $this->rates = null;
        $this->theRatesExpire();

        $this->expectException(InvalidArgumentException::class);
        $this->quote($house, $coupon);
    }

    public function test_a_rate_of_zero_or_less_is_not_a_rate(): void
    {
        // Zero would make every converted price zero.
        $this->rates = ['USD' => 1.10, 'COP' => 0, 'GBP' => -0.85];

        $this->assertSame(1.10, ExchangeRates::exchangeRate('USD'));
        $this->assertNull(ExchangeRates::exchangeRate('COP'));
        $this->assertNull(ExchangeRates::exchangeRate('GBP'));
    }
}
