<?php

namespace Tests\Feature;

use App\Engines\PricingEngine;
use App\Models\Discount;
use App\Models\NightlyRate;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A per-night price from the calendar (dynamic via PriceLabs, or set by
 * hand) takes precedence over everything: base price, weekend adjustment,
 * season and the default, also in another currency. Discounts then simply
 * apply on top of it.
 */
class DynamicPricesPriorityTest extends TestCase
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

    /** A dynamic price of €80 for every night. */
    private function dynamic(Property $accommodation, string $source = NightlyRate::SOURCE_PRICELABS, int $cents = 8000): void
    {
        foreach (self::NIGHTS as $date) {
            NightlyRate::create(['property_id' => $accommodation->id, 'date' => $date, 'price_cents' => $cents, 'source' => $source]);
        }
    }

    private function quote(Property $accommodation)
    {
        return app(PricingEngine::class)->quote($accommodation, CarbonImmutable::parse('2026-03-05'), CarbonImmutable::parse('2026-03-08'), 2);
    }

    public function test_the_dynamic_price_goes_for_the_weekend_adjustment(): void
    {
        foreach ([
            ['weekend_kind' => 'percent', 'weekend_percentage' => 25],
            ['weekend_kind' => 'fixed', 'weekend_price_cents' => 20000],
        ] as $weekend) {
            $accommodation = $this->accommodation($weekend);
            $this->dynamic($accommodation);

            // Friday and Saturday are simply €80 too: no weekend surcharge on top.
            $this->assertSame(3 * 8000, $this->quote($accommodation)->line('accommodation')->amountCents, json_encode($weekend));
        }
    }

    public function test_the_dynamic_price_goes_for_a_season(): void
    {
        $accommodation = $this->accommodation(['weekend_kind' => 'percent', 'weekend_percentage' => 10]);
        $accommodation->rateSeasons()->create([
            'name' => 'Voorjaar', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
            'price_cents' => 20000, 'weekend_price_cents' => 30000,
        ]);
        $this->dynamic($accommodation);

        $this->assertSame(3 * 8000, $this->quote($accommodation)->line('accommodation')->amountCents);
    }

    public function test_only_nights_with_a_dynamic_price_deviate_off(): void
    {
        $accommodation = $this->accommodation(['weekend_kind' => 'percent', 'weekend_percentage' => 25]);
        NightlyRate::create(['property_id' => $accommodation->id, 'date' => '2026-03-06', 'price_cents' => 8000, 'source' => NightlyRate::SOURCE_PRICELABS]);

        // Thursday base price, Friday dynamic, Saturday base price + 25%.
        $this->assertSame(10000 + 8000 + 12500, $this->quote($accommodation)->line('accommodation')->amountCents);
    }

    public function test_the_dynamic_price_goes_for_the_default_also_in_a_other_currency(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => 1.25]]),
            'cdn.jsdelivr.net/*' => Http::response(null, 500),
        ]);
        Property::master()->update(['base_price_cents' => 50000]);
        $accommodation = $this->accommodation(['currency' => 'USD', 'base_price_cents' => null]);
        $this->dynamic($accommodation);

        // The nightly price is already in the property's currency: do not convert.
        $quote = $this->quote($accommodation);
        $this->assertSame('USD', $quote->currency);
        $this->assertSame(3 * 8000, $quote->line('accommodation')->amountCents);
    }

    public function test_a_manual_price_in_the_calendar_goes_just_so_for(): void
    {
        $accommodation = $this->accommodation(['weekend_kind' => 'fixed', 'weekend_price_cents' => 20000]);
        $this->dynamic($accommodation, NightlyRate::SOURCE_MANUAL, 9000);

        $this->assertSame(3 * 9000, $this->quote($accommodation)->line('accommodation')->amountCents);
    }

    public function test_discounts_apply_in_the_dynamic_price(): void
    {
        CarbonImmutable::setTestNow('2026-03-01');
        $accommodation = $this->accommodation(['discounts_add_up' => true]);
        $accommodation->discounts()->create(['kind' => Discount::STAY_DURATION, 'min_nights' => 3, 'percentage' => 10]);
        $accommodation->discounts()->create(['kind' => Discount::LASTMINUTE, 'days_before_arrival' => 7, 'percentage' => 5]);
        $this->dynamic($accommodation);

        $quote = $this->quote($accommodation); // €240 dynamic: 10% + 5%
        $this->assertSame([-2400, -1200], collect($quote->lines)->where('key', 'discount')->pluck('amountCents')->values()->all());

        CarbonImmutable::setTestNow();
    }

    public function test_the_calendar_shows_the_dynamic_price(): void
    {
        $accommodation = $this->accommodation(['weekend_kind' => 'percent', 'weekend_percentage' => 25]);
        $this->dynamic($accommodation);

        // Thursday to Saturday dynamic; the Friday after without: base price + 25%.
        $map = app(PricingEngine::class)->nightlyPriceMap($accommodation, CarbonImmutable::parse('2026-03-05'), CarbonImmutable::parse('2026-03-13'));

        $this->assertSame([8000, 8000, 8000], array_values(array_intersect_key($map, array_flip(self::NIGHTS))));
        $this->assertSame(12500, $map['2026-03-13']);
    }
}
