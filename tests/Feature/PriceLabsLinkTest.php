<?php

namespace Tests\Feature;

use App\Models\NightlyRate;
use App\Models\Property;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The PriceLabs Customer API as documented at developers.pricelabs.co:
 * GET /v1/listings (id, pms, currency) and POST /v1/listing_prices (currency and
 * data[].date/price per listing). Prices come in the currency of the listing
 * at PriceLabs; we convert them to the currency of the accommodation.
 */
class PriceLabsLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Property::master()->update(['pricing_service' => 'pricelabs']);
        Setting::set('prices', 'pricelabs_api_key', 'test-key');
    }

    private function accommodation(string $listingId, array $extra = []): Property
    {
        return Property::create([
            'name' => "Huis {$listingId}", 'slug' => 'house-'.strtolower($listingId), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'external_listing_id' => $listingId, ...$extra,
        ]);
    }

    /** Fake PriceLabs with one listing in the given currency, and fixed exchange rates. */
    private function pricelabs(string $currency = 'EUR', ?array $data = null, array $extraListings = []): void
    {
        $data ??= [
            ['date' => today()->addDays(10)->toDateString(), 'price' => 93, 'user_price' => 250],
            ['date' => today()->addDays(11)->toDateString(), 'price' => 104.5, 'user_price' => -1],
        ];

        Http::fake([
            'api.pricelabs.co/v1/listings' => Http::response(['listings' => [
                ['id' => 'PL111', 'pms' => 'airbnb', 'name' => 'Strandhuis', 'currency' => $currency],
                ...$extraListings,
            ]]),
            'api.pricelabs.co/v1/listing_prices' => fn (Request $request) => Http::response(
                collect($request['listings'])->map(fn (array $listing) => $listing['id'] === 'PL111'
                    ? ['id' => 'PL111', 'pms' => 'airbnb', 'currency' => $currency, 'data' => $data]
                    : ['id' => $listing['id'], 'pms' => $listing['pms'], 'error' => 'No data', 'error_status' => 'LISTING_NO_DATA'])->all()
            ),
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1.25, 'COP' => 5000.0]]),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ]);
    }

    /** @return array<string, int> date => cents */
    private function nightlyPrices(Property $accommodation): array
    {
        return NightlyRate::query()->where('property_id', $accommodation->id)->orderBy('date')->get()
            ->mapWithKeys(fn (NightlyRate $row) => [$row->date->toDateString() => $row->price_cents])->all();
    }

    public function test_the_prices_are_fetched_as_pricelabs_the_documents(): void
    {
        $this->pricelabs();
        $accommodation = $this->accommodation('PL111');

        $this->artisan('prices:sync')->assertSuccessful();

        $this->assertSame([
            today()->addDays(10)->toDateString() => 9300,
            today()->addDays(11)->toDateString() => 10450,
        ], $this->nightlyPrices($accommodation));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.pricelabs.co/v1/listing_prices'
            && $request->header('X-API-Key') === ['test-key']
            && $request['listings'][0]['id'] === 'PL111'
            && $request['listings'][0]['pms'] === 'airbnb'
            && $request['listings'][0]['dateFrom'] === today()->toDateString());
    }

    public function test_prices_in_a_other_currency_are_converted(): void
    {
        $this->pricelabs('USD');
        $accommodation = $this->accommodation('PL111', ['currency' => 'COP']);

        $this->artisan('prices:sync')->assertSuccessful();

        // $93 × 4000 (USD → COP) = COP 372.000.
        $this->assertSame(37200000, $this->nightlyPrices($accommodation)[today()->addDays(10)->toDateString()]);
    }

    public function test_without_exchange_rate_is_nothing_saved(): void
    {
        $this->pricelabs('XYZ');
        $accommodation = $this->accommodation('PL111');
        Log::spy();

        $this->artisan('prices:sync')->assertSuccessful();

        $this->assertSame([], $this->nightlyPrices($accommodation));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $notification) => str_contains($notification, 'no exchange rate'))->once();
    }

    public function test_a_unknown_listing_or_a_listing_without_details_saves_nothing_on(): void
    {
        $this->pricelabs(extraListings: [['id' => 'PL222', 'pms' => 'airbnb', 'currency' => 'EUR']]);
        $unknown = $this->accommodation('BESTAAT-NIET');
        $withoutDetails = $this->accommodation('PL222');

        $this->artisan('prices:sync')->assertSuccessful();

        $this->assertSame([], $this->nightlyPrices($unknown));
        $this->assertSame([], $this->nightlyPrices($withoutDetails));
    }

    public function test_the_list_with_listings_is_a_time_per_synchronization_fetched(): void
    {
        $this->pricelabs(extraListings: [['id' => 'PL222', 'pms' => 'airbnb', 'currency' => 'EUR']]);
        $this->accommodation('PL111');
        $this->accommodation('PL222');

        $this->artisan('prices:sync')->assertSuccessful();

        Http::assertSentCount(3); // 1× listings, 2× listing_prices (rates not needed: everything in euros)
    }
}
