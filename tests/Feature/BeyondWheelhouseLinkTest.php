<?php

namespace Tests\Feature;

use App\Models\NightlyRate;
use App\Models\Property;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Beyond and Wheelhouse as in their documentation:
 * - Beyond: developers.beyondpricing.com, JSON:API, whole currency units,
 *   currency on the listing;
 * - Wheelhouse: api.usewheelhouse.com/ss_api/v1, X-Integration-Api-Key,
 *   price_recommendations with stay_date/price/currency per row.
 */
class BeyondWheelhouseLinkTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(string $service, string $listingId, array $extra = []): Property
    {
        Setting::set('prices', "{$service}_api_key", 'test-key');

        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis-'.$service, 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'pricing_service' => $service, 'external_listing_id' => $listingId, ...$extra,
        ]);
    }

    private function exchangeRates(): array
    {
        return [
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1.25, 'COP' => 5000.0]]),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ];
    }

    /** @return array<string, int> */
    private function nightlyPrices(Property $accommodation): array
    {
        return NightlyRate::query()->where('property_id', $accommodation->id)->orderBy('date')->get()
            ->mapWithKeys(fn (NightlyRate $row) => [$row->date->toDateString() => $row->price_cents])->all();
    }

    public function test_beyond_according_to_the_documentation(): void
    {
        $day1 = today()->addDays(5)->toDateString();
        $day2 = today()->addDays(6)->toDateString();

        Http::fake([
            'developers.beyondpricing.com/api/v1/listings/123/calendar/*' => Http::response([
                'data' => [
                    ['type' => 'calendar-entries', 'id' => $day1, 'attributes' => ['date' => $day1, 'price' => 101, 'price-posted' => 100]],
                    ['type' => 'calendar-entries', 'id' => $day2, 'attributes' => ['date' => $day2, 'price' => 120, 'price-posted' => null]],
                ],
                'meta' => ['pagination' => ['page' => 1, 'pages' => 1, 'count' => 2]],
                'links' => ['next' => null],
            ]),
            'developers.beyondpricing.com/api/v1/listings/123/' => Http::response([
                'data' => ['type' => 'listings', 'id' => '123', 'attributes' => ['currency' => 'USD', 'base-price' => 189]],
            ]),
            ...$this->exchangeRates(),
        ]);

        $accommodation = $this->accommodation('beyond', '123', ['currency' => 'USD']);
        $this->artisan('prices:sync')->assertSuccessful();

        // Whole units ×100; posted price takes precedence, otherwise the recommendation.
        $this->assertSame([$day1 => 10000, $day2 => 12000], $this->nightlyPrices($accommodation));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/listings/123/calendar/')
            && $request->header('Authorization') === ['Bearer test-key']
            && str_contains(urldecode($request->url()), 'filter[start-date]='.today()->toDateString())
            && str_contains(urldecode($request->url()), 'page[size]=731'));
    }

    public function test_beyond_converts_to_to_the_currency_of_the_accommodation(): void
    {
        $day = today()->addDays(5)->toDateString();
        Http::fake([
            'developers.beyondpricing.com/api/v1/listings/123/calendar/*' => Http::response([
                'data' => [['attributes' => ['date' => $day, 'price' => 100, 'price-posted' => 100]]],
                'links' => ['next' => null],
            ]),
            'developers.beyondpricing.com/api/v1/listings/123/' => Http::response(['data' => ['attributes' => ['currency' => 'USD']]]),
            ...$this->exchangeRates(),
        ]);

        $accommodation = $this->accommodation('beyond', '123'); // accommodation in euros
        $this->artisan('prices:sync')->assertSuccessful();

        // $100 / 1,25 = €80.
        $this->assertSame([$day => 8000], $this->nightlyPrices($accommodation));
    }

    public function test_wheelhouse_according_to_the_documentation(): void
    {
        $day1 = today()->addDays(5)->toDateString();
        $day2 = today()->addDays(6)->toDateString();

        Http::fake([
            'api.usewheelhouse.com/ss_api/v1/listings/abc-1/price_recommendations*' => Http::response([
                'error_messages' => [], 'api_warnings' => [],
                'data' => [
                    ['stay_date' => $day1, 'price' => 150, 'currency' => 'COP', 'min_stay' => 2],
                    ['stay_date' => $day2, 'price' => 175.5, 'currency' => 'COP', 'min_stay' => 2],
                ],
                'base_price' => 140,
            ]),
            'api.usewheelhouse.com/ss_api/v1/listings*' => Http::response([
                ['id' => 'abc-1', 'wheelhouse_id' => 77, 'channel' => 'airbnb', 'currency' => 'COP'],
            ]),
            ...$this->exchangeRates(),
        ]);

        $accommodation = $this->accommodation('wheelhouse', 'abc-1', ['currency' => 'COP']);
        $this->artisan('prices:sync')->assertSuccessful();

        $this->assertSame([$day1 => 15000, $day2 => 17550], $this->nightlyPrices($accommodation));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/listings/abc-1/price_recommendations')
            && $request->header('X-Integration-Api-Key') === ['test-key']
            && $request['channel'] === 'airbnb');
    }

    public function test_wheelhouse_saves_nothing_on_for_a_unknown_listing_or_a_error(): void
    {
        Http::fake([
            'api.usewheelhouse.com/ss_api/v1/listings/abc-1/price_recommendations*' => Http::response(['error' => 'Locked'], 423),
            'api.usewheelhouse.com/ss_api/v1/listings*' => Http::response([
                ['id' => 'abc-1', 'channel' => 'airbnb', 'currency' => 'EUR'],
            ]),
            ...$this->exchangeRates(),
        ]);

        $stillBusy = $this->accommodation('wheelhouse', 'abc-1');
        $this->artisan('prices:sync')->assertSuccessful();
        $this->assertSame([], $this->nightlyPrices($stillBusy));

        $stillBusy->update(['external_listing_id' => 'exists-not']);
        $this->artisan('prices:sync')->assertSuccessful();
        $this->assertSame([], $this->nightlyPrices($stillBusy));
    }
}
