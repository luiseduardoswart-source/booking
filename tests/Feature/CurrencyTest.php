<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Support\Currencies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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

    /**
     * A guest who picked dollars downloads an invoice from My bookings: the
     * document names what was charged, in euros, not today's converted figure.
     */
    public function test_a_document_shows_the_currency_that_was_charged(): void
    {
        $this->enableCurrency();
        $booking = app(\App\Engines\BookingEngine::class)->create(
            $this->property(), \Carbon\CarbonImmutable::tomorrow(), \Carbon\CarbonImmutable::tomorrow()->addDays(2), 2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        // The visitor's choice applies on the page...
        $this->withSession(['currency' => 'USD'])->get('/valutahuis')->assertOk()->assertSee('110');
        $this->assertSame('USD', \App\Support\Money::displayCurrency());

        // ...and not in the document made during the same visit.
        $inDocument = null;
        \Illuminate\Support\Facades\View::composer('pdf.confirmation', function () use (&$inDocument) {
            $inDocument = \App\Support\Money::format(10000, 'EUR');
        });
        app(\App\Engines\DocumentEngine::class)->confirmation($booking);

        $this->assertSame('€ 100,00', $inDocument);
        $this->assertSame('USD', \App\Support\Money::displayCurrency()); // the page itself is untouched
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

    public function test_the_most_used_currencies_are_at_the_top_then_a_line_then_alphabetical(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => [
                'AED' => 4.0, 'ARS' => 1000.0, 'COP' => 4500.0, 'GBP' => 0.85, 'JPY' => 160.0, 'USD' => 1.10,
            ]]),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ]);
        Setting::set('site', 'currency_picker', '1');

        // Default: EUR, USD, JPY, GBP and COP (this site is for Colombia).
        $this->assertSame(['EUR', 'USD', 'JPY', 'GBP', 'COP', 'AED', 'ARS'], Currencies::codes());

        $this->get("/{$this->property()->slug}")->assertOk()
            ->assertSeeInOrder([
                route('currency.switch', 'EUR'), route('currency.switch', 'USD'), route('currency.switch', 'JPY'),
                route('currency.switch', 'GBP'), route('currency.switch', 'COP'), 'data-currency-divider', route('currency.switch', 'AED'),
            ], false);
    }

    public function test_a_popular_currency_without_a_rate_is_left_out(): void
    {
        $this->enableCurrency(); // USD, GBP and COP, no JPY

        $this->assertSame(['EUR', 'USD', 'GBP', 'COP'], Currencies::codes());
    }

    public function test_the_owner_chooses_the_currencies_at_the_top_in_settings(): void
    {
        $this->enableCurrency(); // USD, GBP and COP

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(Settings::class)
            ->assertFormSet(['site_currency_popular' => Currencies::POPULAR])
            ->fillForm(['company_name' => 'Casa Cartagena', 'site_currency_popular' => ['COP', 'USD']])
            ->call('save')
            ->assertHasNoFormErrors();

        // In the order of choosing; EUR is now just one of the others.
        $this->assertSame('COP,USD', Setting::get('site', 'currency_popular'));
        $this->assertSame(['COP', 'USD', 'EUR', 'GBP'], Currencies::codes());

        $this->get("/{$this->property()->slug}")->assertOk()
            ->assertSeeInOrder([route('currency.switch', 'COP'), route('currency.switch', 'USD'), 'data-currency-divider', route('currency.switch', 'EUR')], false);
    }

    public function test_no_currencies_at_the_top_means_alphabetical_without_a_line(): void
    {
        $this->enableCurrency();
        Setting::set('site', 'currency_popular', '');

        $this->assertSame(['COP', 'EUR', 'GBP', 'USD'], Currencies::codes());
        $this->get("/{$this->property()->slug}")->assertOk()->assertDontSee('data-currency-divider', false);
    }
}
