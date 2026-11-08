<?php

namespace Tests\Feature;

use App\Engines\AvailabilityEngine;
use App\Engines\PricingEngine;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use App\Payments\PaymentProvider;
use App\Payments\StripeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MasterDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Leeg Huis',
            'slug' => 'empty-house-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => null,
        ], $overrides));
    }

    private function quote(Property $property, string $arrival, string $departure, int $guests = 2)
    {
        return (new PricingEngine)->quote(
            $property,
            CarbonImmutable::parse($arrival),
            CarbonImmutable::parse($departure),
            $guests,
        );
    }

    public function test_empty_price_fields_fall_back_on_the_defaults(): void
    {
        $master = Property::master();
        $master->update(['base_price_cents' => 15000, 'cleaning_fee_cents' => 4000]);

        $property = $this->makeProperty();

        // 2 nights at €150 (default) + €40 cleaning (default)
        $quote = $this->quote($property, '2026-03-02', '2026-03-04');

        $this->assertSame(34000, $quote->totalCents);
    }

    public function test_own_price_overrides_the_default(): void
    {
        Property::master()->update(['base_price_cents' => 15000]);

        $property = $this->makeProperty(['base_price_cents' => 8000]);

        $quote = $this->quote($property, '2026-03-02', '2026-03-04');

        $this->assertSame(16000, $quote->totalCents);
    }

    public function test_default_seasons_apply_without_own_seasons(): void
    {
        $master = Property::master();
        $master->update(['base_price_cents' => 10000]);
        $master->rateSeasons()->create([
            'name' => 'Master hoogseizoen',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-31',
            'price_cents' => 20000,
        ]);

        $property = $this->makeProperty();

        $quote = $this->quote($property, '2026-07-06', '2026-07-08');
        $this->assertSame(40000, $quote->totalCents);

        // Own season? Then the master seasons no longer apply.
        $property->rateSeasons()->create([
            'name' => 'Eigen seizoen',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-31',
            'price_cents' => 12000,
        ]);

        $this->assertSame(24000, $this->quote($property->fresh(), '2026-07-06', '2026-07-08')->totalCents);
    }

    public function test_default_discounts_and_booking_rules_apply_as_fallback(): void
    {
        $master = Property::master();
        $master->update(['base_price_cents' => 10000]);
        $master->discounts()->create(['min_nights' => 7, 'percentage' => 10]);
        $master->bookingRules()->create(['min_nights' => 3]);

        $property = $this->makeProperty();

        // Master rule: minimum 3 nights.
        $availability = new AvailabilityEngine;
        $this->assertFalse($availability->isAvailable($property, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-04')));
        $this->assertTrue($availability->isAvailable($property, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-05')));

        // Master discount: 7 nights → 10% (€700 - €70).
        $this->assertSame(63000, $this->quote($property, '2026-03-02', '2026-03-09')->totalCents);
    }

    public function test_maximum_rental_period_falls_back_on_the_defaults(): void
    {
        Property::master()->update(['max_stay_nights' => 28]);
        $property = $this->makeProperty();

        $availability = new AvailabilityEngine;
        $this->assertTrue($availability->isAvailable($property, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-30')));
        $this->assertFalse($availability->isAvailable($property, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-31')));

        // Own value overrides the default.
        $property->update(['max_stay_nights' => 40]);
        $this->assertTrue($availability->isAvailable($property->fresh(), CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-31')));
    }

    public function test_master_record_is_invisible_on_the_site_and_in_the_admin(): void
    {
        Property::master(); // make sure it exists
        $this->makeProperty(['name' => 'Zichtbaar Huis']);
        $this->makeProperty(['name' => 'Tweede Huis']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Standaarden');

        $this->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_defaults_page_renders_in_the_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator, 'locale' => 'nl']);

        $response = $this->actingAs($admin)
            ->get('/admin/properties/defaults');

        $response->assertOk();

        // The "Standaard accommodatie-instellingen" navigation item must light up as
        // active on this page, and not the "Accommodaties" item (it is not a
        // property).
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);

        $defaultsLink = $xpath->query("//a[contains(., 'Standaard accommodatie-instellingen')]")->item(0);
        $this->assertNotNull($defaultsLink, 'Nav item "Standaard accommodatie-instellingen" not found.');
        $this->assertStringContainsString('fi-active', $defaultsLink->parentNode->getAttribute('class'));

        $accommodationsLink = $xpath->query("//a[contains(., 'Accommodaties')]")->item(0);
        $this->assertNotNull($accommodationsLink, 'Nav item "Accommodaties" not found.');
        $this->assertStringNotContainsString('fi-active', $accommodationsLink->parentNode->getAttribute('class'));
    }

    public function test_stripe_provider_is_chosen_via_the_settings(): void
    {
        \App\Models\Setting::set('payments', 'provider', 'stripe');

        $this->assertInstanceOf(StripeProvider::class, app(PaymentProvider::class));
    }

    public function test_defaults_save_works_without_cancellation_policy(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        Property::master()->update(['cancellation_policy' => null]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\Properties\Pages\EditDefaults::class)
            ->fillForm(['deposit_cents' => 100]) // €100 deposit
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(10000, Property::master()->fresh()->deposit_cents);
    }
}
