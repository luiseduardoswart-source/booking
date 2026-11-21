<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\NightlyPricesCalendar;
use App\Engines\PricingEngine;
use App\Models\NightlyRate;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Services\BeyondProvider;
use App\Services\FakeDynamicPricingProvider;
use App\Services\NullDynamicPricingProvider;
use App\Services\PriceLabsProvider;
use App\Services\PricingServices;
use App\Services\WheelhouseProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NightlyPricingTest extends TestCase
{
    use RefreshDatabase;

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Prijshuis',
            'slug' => 'prijshuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000, // €100/night
        ], $overrides));
    }

    private function usage(FakeDynamicPricingProvider $fake): void
    {
        Property::master()->update(['pricing_service' => 'pricelabs']);
        $this->app->instance(PriceLabsProvider::class, $fake);
    }

    public function test_night_override_wins_of_base_price(): void
    {
        $property = $this->makeProperty();
        // Set the second night to €150.
        NightlyRate::create(['property_id' => $property->id, 'date' => '2026-03-03', 'price_cents' => 15000, 'source' => 'manual']);

        $quote = app(PricingEngine::class)->quote(
            $property,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
        );

        // 3 nights: 100 + 150 + 100 = 350 stay (no cleaning fee set).
        $accommodation = collect($quote->lines)->firstWhere('key', 'accommodation');
        $this->assertSame(35000, $accommodation->amountCents);
    }

    public function test_nightly_price_map_gives_effective_price_per_night(): void
    {
        $property = $this->makeProperty();
        NightlyRate::create(['property_id' => $property->id, 'date' => '2026-03-03', 'price_cents' => 15000, 'source' => 'manual']);

        $map = app(PricingEngine::class)->nightlyPriceMap(
            $property,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-04'),
        );

        $this->assertSame(10000, $map['2026-03-02']);
        $this->assertSame(15000, $map['2026-03-03']); // override
        $this->assertSame(10000, $map['2026-03-04']);
    }

    public function test_pricelabs_sync_saves_nightly_prices_on(): void
    {
        $day1 = today()->addDays(10)->toDateString();
        $day2 = today()->addDays(11)->toDateString();

        $fake = new FakeDynamicPricingProvider;
        $fake->prices = [$day1 => 12000, $day2 => 13500];
        $this->usage($fake);

        $property = $this->makeProperty(['external_listing_id' => 'PL-123']);

        $this->artisan('prices:sync')->assertSuccessful();

        $this->assertDatabaseHas('nightly_rates', [
            'property_id' => $property->id, 'date' => $day1, 'price_cents' => 12000, 'source' => 'pricelabs',
        ]);
        $this->assertDatabaseHas('nightly_rates', [
            'property_id' => $property->id, 'date' => $day2, 'price_cents' => 13500, 'source' => 'pricelabs',
        ]);
    }

    /** A night for nothing is not a price a pricing service means: a free night could be booked. */
    public function test_a_dynamic_price_of_zero_is_not_taken_over(): void
    {
        $day1 = today()->addDays(10)->toDateString();
        $day2 = today()->addDays(11)->toDateString();

        $fake = new FakeDynamicPricingProvider;
        $fake->prices = [$day1 => 12000, $day2 => 13500];
        $this->usage($fake);
        $property = $this->makeProperty(['external_listing_id' => 'PL-123']);
        $this->artisan('prices:sync')->assertSuccessful();

        // The next day the service answers zero for one night and less for the other.
        $fake->prices = [$day1 => 0, $day2 => -500];
        $this->artisan('prices:sync')->assertSuccessful();

        // Yesterday's prices stay.
        $this->assertDatabaseHas('nightly_rates', ['property_id' => $property->id, 'date' => $day1, 'price_cents' => 12000]);
        $this->assertDatabaseHas('nightly_rates', ['property_id' => $property->id, 'date' => $day2, 'price_cents' => 13500]);
    }

    public function test_sync_saves_in_without_configuration(): void
    {
        $fake = new FakeDynamicPricingProvider;
        $fake->configured = false;
        $this->usage($fake);

        $this->makeProperty(['external_listing_id' => 'PL-123']);

        $this->artisan('prices:sync')->assertSuccessful();
        $this->assertDatabaseCount('nightly_rates', 0);
    }

    public function test_sync_ignores_accommodations_without_listing_id(): void
    {
        $fake = new FakeDynamicPricingProvider;
        $fake->prices = [today()->addDays(10)->toDateString() => 12000];
        $this->usage($fake);

        $this->makeProperty(); // no listing id

        $this->artisan('prices:sync')->assertSuccessful();
        $this->assertDatabaseCount('nightly_rates', 0);
    }

    public function test_admin_price_calendar_saves_night_on_and_set_back(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $property = $this->makeProperty();
        $date = today()->addDays(20)->toDateString();

        Livewire::actingAs($admin)
            ->test(NightlyPricesCalendar::class, ['property' => $property])
            ->set('selected', [$date])
            ->set('priceInput', '175,50')
            ->call('save');

        $this->assertDatabaseHas('nightly_rates', [
            'property_id' => $property->id, 'date' => $date, 'price_cents' => 17550, 'source' => 'manual',
        ]);

        // Resetting to default removes the override.
        Livewire::actingAs($admin)
            ->test(NightlyPricesCalendar::class, ['property' => $property])
            ->set('selected', [$date])
            ->call('restore');

        $this->assertDatabaseMissing('nightly_rates', [
            'property_id' => $property->id, 'date' => $date,
        ]);
    }

    public function test_chosen_pricing_service_determines_the_provider(): void
    {
        $property = $this->makeProperty();
        $services = app(PricingServices::class);

        foreach (['beyond' => BeyondProvider::class, 'wheelhouse' => WheelhouseProvider::class, 'pricelabs' => PriceLabsProvider::class] as $service => $class) {
            Property::master()->update(['pricing_service' => $service]);
            $this->assertInstanceOf($class, $services->before($property));
        }

        // Without a choice: the null provider that does nothing.
        Property::master()->update(['pricing_service' => PricingServices::NO]);
        $provider = $services->before($property);
        $this->assertInstanceOf(NullDynamicPricingProvider::class, $provider);
        $this->assertFalse($provider->isConfigured());
    }

    public function test_calendar_blocks_and_unblocks_nights(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $property = $this->makeProperty();
        $date = today()->addDays(15)->toDateString();

        Livewire::actingAs($admin)
            ->test(NightlyPricesCalendar::class, ['property' => $property])
            ->set('selected', [$date])
            ->call('block');

        $this->assertTrue($property->calendarBlocks()->whereDate('start_date', $date)->whereNull('source')->exists());

        Livewire::actingAs($admin)
            ->test(NightlyPricesCalendar::class, ['property' => $property])
            ->set('selected', [$date])
            ->call('unblock');

        $this->assertFalse($property->calendarBlocks()->whereDate('start_date', $date)->exists());
    }

    public function test_calendar_discount_lowers_the_nightly_price(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $property = $this->makeProperty(); // base price €100
        $date = today()->addDays(16)->toDateString();

        Livewire::actingAs($admin)
            ->test(NightlyPricesCalendar::class, ['property' => $property])
            ->set('selected', [$date])
            ->set('discountInput', '20')
            ->call('discount');

        // 20% discount on €100 → €80, with a visible discount percentage.
        $this->assertDatabaseHas('nightly_rates', [
            'property_id' => $property->id, 'date' => $date, 'price_cents' => 8000, 'discount_percent' => 20, 'source' => 'manual',
        ]);

        // Remove the discount again via "Reset to default".
        Livewire::actingAs($admin)
            ->test(NightlyPricesCalendar::class, ['property' => $property])
            ->set('selected', [$date])
            ->call('restore');

        $this->assertDatabaseMissing('nightly_rates', ['property_id' => $property->id, 'date' => $date]);
    }

    public function test_endpoint_shows_prices_only_as_the_setting_on_is(): void
    {
        $property = $this->makeProperty();
        NightlyRate::create(['property_id' => $property->id, 'date' => today()->addDays(2)->toDateString(), 'price_cents' => 15000, 'source' => 'manual']);

        $url = "/api/v1/properties/{$property->id}/unavailable-dates";

        // Off: no prices key.
        $this->getJson($url)->assertOk()->assertJsonMissingPath('prices');

        // On: prices map present and compactly formatted.
        Property::master()->update(['prices_in_calendar' => true]);
        $response = $this->getJson($url)->assertOk()->assertJsonStructure(['dates', 'prices']);
        $prices = $response->json('prices');
        $this->assertSame('€150', $prices[today()->addDays(2)->toDateString()]);
    }
}
