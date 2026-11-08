<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * After a language or currency switch you return to the page you were on, even
 * if the booking block or the search field has fetched data in the meantime.
 */
class PreviousPageTest extends TestCase
{
    use RefreshDatabase;

    private Property $accommodation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Setting::set('site', 'currency_picker', '1');
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1.1]]),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ]);

        $this->accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    /** What the booking block does after the guest has picked dates. */
    private function viewPageAndFetchPrice(): void
    {
        $this->get('/strandhuis')->assertOk();
        $this->getJson(route('api.properties.quote', [
            'property' => $this->accommodation,
            'arrival' => today()->addDays(30)->toDateString(),
            'departure' => today()->addDays(33)->toDateString(),
            'guests' => 2,
        ]))->assertOk();
    }

    public function test_after_a_currency_switch_back_to_the_accommodation_and_not_to_the_price_calculation(): void
    {
        $this->viewPageAndFetchPrice();

        $this->get('/currency/USD')->assertRedirect(url('/strandhuis'));
    }

    public function test_after_a_language_switch_back_to_the_accommodation(): void
    {
        $this->viewPageAndFetchPrice();

        $target = $this->get('/language/en')->assertRedirect()->headers->get('Location');

        $this->assertStringNotContainsString('/api/', $target);
        $this->assertStringContainsString('strandhuis', $target);
    }

    public function test_search_suggestions_count_also_not_as_previous_page(): void
    {
        $this->get('/strandhuis')->assertOk();
        $this->get(route('api.search_suggestions', ['q' => 'stra']))->assertOk();

        $this->get('/currency/USD')->assertRedirect(url('/strandhuis'));
    }

    public function test_a_regular_page_counts_still_does_as_previous_page(): void
    {
        $this->get('/strandhuis')->assertOk();
        $this->get('/')->assertOk();

        $this->get('/currency/USD')->assertRedirect(url('/'));
    }
}
