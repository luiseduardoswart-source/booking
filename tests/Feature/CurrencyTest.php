<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function property(): Property
    {
        return Property::create([
            'name' => 'Valutahuis', 'slug' => 'valutahuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    private function enableCurrency(): void
    {
        Setting::set('site', 'currency_picker', '1');

        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1.10, 'GBP' => 0.85, 'COP' => 4500.0]]),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ]);
    }

    public function test_currency_switch_sets_the_session_and_shows_converted_prices(): void
    {
        $this->enableCurrency();
        $property = $this->property();

        $this->get('/currency/USD')->assertRedirect();

        // €100 per night × 1.10 = $110 — and the euro display is gone.
        $response = $this->withSession(['currency' => 'USD', 'locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertDontSee('€ 100,00');

        $this->assertStringContainsString('110', $response->getContent());
    }

    public function test_unknown_currency_is_rejected(): void
    {
        $this->enableCurrency();

        $this->get('/currency/XXX')->assertNotFound();
    }

    public function test_without_exchange_rate_everything_falls_back_to_euro(): void
    {
        Setting::set('site', 'currency_picker', '1');
        Http::fake([
            'open.er-api.com/*' => Http::response(null, 500),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ]);

        $property = $this->property();

        $this->withSession(['currency' => 'USD'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('€ 100,00');
    }

    public function test_disabled_picker_always_shows_euro(): void
    {
        $property = $this->property();

        $this->withSession(['currency' => 'USD'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('€ 100,00');
    }

    public function test_cop_is_supported_and_converted(): void
    {
        $this->enableCurrency();
        $property = $this->property();

        // €100 × 4500 = COP 450.000 — exactly the case that did not work at first.
        $response = $this->withSession(['currency' => 'COP', 'locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertDontSee('€ 100,00');

        $this->assertStringContainsString('450', $response->getContent());
    }

    public function test_default_currency_applies_without_own_choice(): void
    {
        $this->enableCurrency();
        Setting::set('site', 'currency_default', 'USD');
        $property = $this->property();

        // No session choice: the visitor sees dollars right away.
        $response = $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertDontSee('€ 100,00');

        $this->assertStringContainsString('110', $response->getContent());
    }

    public function test_picker_appears_only_when_enabled(): void
    {
        $this->enableCurrency();
        $property = $this->property();

        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertSee(route('currency.switch', 'USD'));

        Setting::set('site', 'currency_picker', '0');

        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertDontSee(route('currency.switch', 'USD'));
    }
}
