<?php

namespace Tests\Feature;

use App\Engines\PricingEngine;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\NightlyRate;
use App\Models\Property;
use App\Models\User;
use App\Services\FakeDynamicPricingProvider;
use App\Services\PriceLabsProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * With Airbnb or Vrbo next to the own site, PriceLabs is set up with a
 * surcharge for their commission. On the own site that surcharge comes off
 * again: a percentage or a fixed amount off the dynamic price. Manual prices
 * in the calendar can go before the dynamic price.
 */
class DynamicPriceDiscountTest extends TestCase
{
    use RefreshDatabase;

    /** Thursday, Friday and Saturday. */
    private const NIGHTS = ['2026-03-05', '2026-03-06', '2026-03-07'];

    private function accommodation(array $extra = []): Property
    {
        static $counter = 0;
        $counter++;

        return Property::create([
            'name' => 'Strandhuis', 'slug' => "strandhuis-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, ...$extra,
        ]);
    }

    /** PriceLabs says €118 on Thursday and Friday; Saturday has a manual €90. */
    private function prices(Property $accommodation): void
    {
        foreach (['2026-03-05', '2026-03-06'] as $date) {
            NightlyRate::create(['property_id' => $accommodation->id, 'date' => $date, 'price_cents' => 11800, 'source' => NightlyRate::SOURCE_PRICELABS]);
        }
        NightlyRate::create(['property_id' => $accommodation->id, 'date' => '2026-03-07', 'price_cents' => 9000, 'source' => NightlyRate::SOURCE_MANUAL]);
    }

    /** @return array<int, int> the price of each of the three nights */
    private function nights(Property $accommodation): array
    {
        $map = app(PricingEngine::class)->nightlyPriceMap($accommodation, CarbonImmutable::parse(self::NIGHTS[0]), CarbonImmutable::parse(self::NIGHTS[2]));

        return array_values($map);
    }

    private function stay(Property $accommodation): int
    {
        return app(PricingEngine::class)
            ->quote($accommodation, CarbonImmutable::parse(self::NIGHTS[0]), CarbonImmutable::parse('2026-03-08'), 2)
            ->line('accommodation')->amountCents;
    }

    public function test_without_discount_the_dynamic_price_stays_as_it_is(): void
    {
        $accommodation = $this->accommodation();
        $this->prices($accommodation);

        $this->assertSame([11800, 11800, 9000], $this->nights($accommodation));
    }

    public function test_a_percentage_comes_off_the_dynamic_price_only(): void
    {
        $accommodation = $this->accommodation(['dynamic_discount_kind' => 'percent', 'dynamic_discount_percentage' => 18]);
        $this->prices($accommodation);

        // 118 minus 18% = 96.76; the manual price stays 90.
        $this->assertSame([9676, 9676, 9000], $this->nights($accommodation));
        $this->assertSame(9676 + 9676 + 9000, $this->stay($accommodation));
    }

    public function test_a_fixed_amount_comes_off_per_night_and_never_below_zero(): void
    {
        $accommodation = $this->accommodation(['dynamic_discount_kind' => 'fixed', 'dynamic_discount_cents' => 1500]);
        $this->prices($accommodation);

        $this->assertSame([10300, 10300, 9000], $this->nights($accommodation));

        $accommodation->update(['dynamic_discount_cents' => 20000]);
        $this->assertSame([0, 0, 9000], $this->nights($accommodation));
    }

    public function test_the_discount_does_not_touch_nights_without_a_dynamic_price(): void
    {
        $accommodation = $this->accommodation(['dynamic_discount_kind' => 'percent', 'dynamic_discount_percentage' => 50]);

        // Base price €100, no PriceLabs price: no discount.
        $this->assertSame(10000, $this->nights($accommodation)[0]);
    }

    public function test_an_accommodation_follows_the_discount_of_the_default(): void
    {
        Property::master()->update(['dynamic_discount_kind' => 'percent', 'dynamic_discount_percentage' => 10]);
        $follows = $this->accommodation();
        $own = $this->accommodation(['dynamic_discount_kind' => 'none']);
        $this->prices($follows);
        $this->prices($own);

        $this->assertSame(10620, $this->nights($follows)[0]);
        $this->assertSame(11800, $this->nights($own)[0]);
    }

    public function test_only_the_value_of_the_chosen_kind_is_kept(): void
    {
        $accommodation = $this->accommodation(['dynamic_discount_kind' => 'percent', 'dynamic_discount_percentage' => 18]);

        $accommodation->update(['dynamic_discount_kind' => 'fixed', 'dynamic_discount_cents' => 1000]);
        $this->assertNull($accommodation->refresh()->dynamic_discount_percentage);

        $accommodation->update(['dynamic_discount_kind' => null]);
        $this->assertNull($accommodation->refresh()->dynamic_discount_cents);
    }

    public function test_the_discount_and_the_choice_are_saved_from_the_accommodation_screen(): void
    {
        $accommodation = $this->accommodation();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['dynamic_discount_kind' => 'percent'])
            ->fillForm(['dynamic_discount_percentage' => 18, 'manual_prices_win' => '1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame('percent', $accommodation->dynamic_discount_kind);
        $this->assertEquals(18, $accommodation->dynamic_discount_percentage);
        $this->assertTrue($accommodation->manualPricesWin());
    }

    /** PriceLabs has a price for two nights; one of them has a manual price. */
    private function sync(Property $accommodation): void
    {
        $day1 = today()->addDays(10)->toDateString();
        $day2 = today()->addDays(11)->toDateString();

        $fake = new FakeDynamicPricingProvider;
        $fake->prices = [$day1 => 12000, $day2 => 13000];
        Property::master()->update(['pricing_service' => 'pricelabs']);
        $this->app->instance(PriceLabsProvider::class, $fake);

        NightlyRate::create(['property_id' => $accommodation->id, 'date' => $day1, 'price_cents' => 9000, 'source' => NightlyRate::SOURCE_MANUAL]);

        $this->artisan('prices:sync')->assertSuccessful();
    }

    /** @return array<int, array{int, string}> price and source per night */
    private function stored(Property $accommodation): array
    {
        return NightlyRate::query()->where('property_id', $accommodation->id)->orderBy('date')->get()
            ->map(fn (NightlyRate $rate) => [$rate->price_cents, $rate->source])->all();
    }

    public function test_by_default_the_dynamic_price_overwrites_a_manual_price(): void
    {
        $accommodation = $this->accommodation(['external_listing_id' => 'PL1']);
        $this->sync($accommodation);

        $this->assertSame([[12000, 'pricelabs'], [13000, 'pricelabs']], $this->stored($accommodation));
    }

    public function test_a_manual_price_stays_when_manual_prices_go_first(): void
    {
        $accommodation = $this->accommodation(['external_listing_id' => 'PL1', 'manual_prices_win' => true]);
        $this->sync($accommodation);

        $this->assertSame([[9000, 'manual'], [13000, 'pricelabs']], $this->stored($accommodation));
    }

    public function test_the_choice_follows_the_default(): void
    {
        Property::master()->update(['manual_prices_win' => true]);
        $accommodation = $this->accommodation(['external_listing_id' => 'PL1']);
        $this->sync($accommodation);

        $this->assertSame([[9000, 'manual'], [13000, 'pricelabs']], $this->stored($accommodation));
    }
}
