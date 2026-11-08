<?php

namespace Tests\Unit;

use App\Engines\PricingEngine;
use App\Models\Property;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    private PricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new PricingEngine;
    }

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Testhuis',
            'slug' => 'testhuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 6,
            'base_price_cents' => 10000, // €100 per night
            'cleaning_fee_cents' => 0,
            'tourist_tax_cents' => 0,
            'extra_guest_fee_cents' => 0,
            'deposit_cents' => 0,
        ], $overrides));
    }

    private function quote(Property $property, string $arrival, string $departure, int $guests = 2, int $pets = 0)
    {
        return $this->engine->quote(
            $property,
            CarbonImmutable::parse($arrival),
            CarbonImmutable::parse($departure),
            $guests,
            $pets,
        );
    }

    public function test_pet_surcharge_is_one_time_per_pet(): void
    {
        $property = $this->makeProperty(['pet_fee_cents' => 2500]); // €25 per pet

        // 3 nights, 2 pets → 2 × €25 = €50 (independent of the number of nights).
        $quote = $this->quote($property, '2026-03-02', '2026-03-05', 2, 2);

        $this->assertSame(5000, $quote->line('pet_fee')->amountCents);

        // Without pets there is no surcharge line.
        $this->assertNull($this->quote($property, '2026-03-02', '2026-03-05', 2, 0)->line('pet_fee'));
    }

    public function test_base_price_times_count_nights(): void
    {
        // Mon 2 to Thu 5 March 2026 = 3 weekday nights
        $quote = $this->quote($this->makeProperty(), '2026-03-02', '2026-03-05');

        $this->assertSame(3, $quote->nights);
        $this->assertSame(30000, $quote->totalCents);
    }

    public function test_weekend_price_applies_for_friday_and_saturday_night(): void
    {
        $property = $this->makeProperty(['weekend_price_cents' => 15000]);

        // Thu 5 to Sun 8 March 2026: Thu night €100, Fri night €150, Sat night €150
        $quote = $this->quote($property, '2026-03-05', '2026-03-08');

        $this->assertSame(40000, $quote->totalCents);
    }

    public function test_without_weekend_price_applies_the_base_price_in_the_weekend(): void
    {
        $quote = $this->quote($this->makeProperty(), '2026-03-06', '2026-03-08');

        $this->assertSame(20000, $quote->totalCents);
    }

    public function test_season_price_overrides_base_price(): void
    {
        $property = $this->makeProperty();
        $property->rateSeasons()->create([
            'name' => 'Hoogseizoen',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-31',
            'price_cents' => 20000,
        ]);

        // Mon 6 to Wed 8 July = 2 nights in the season
        $quote = $this->quote($property, '2026-07-06', '2026-07-08');

        $this->assertSame(40000, $quote->totalCents);
    }

    public function test_stay_half_inside_season_converts_per_night(): void
    {
        $property = $this->makeProperty();
        $property->rateSeasons()->create([
            'name' => 'Hoogseizoen',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-31',
            'price_cents' => 20000,
        ]);

        // Mon 29 June to Fri 3 July: 29+30 June at €100, 1+2 July at €200
        $quote = $this->quote($property, '2026-06-29', '2026-07-03');

        $this->assertSame(60000, $quote->totalCents);
    }

    public function test_season_weekend_price_inside_season(): void
    {
        $property = $this->makeProperty(['weekend_price_cents' => 15000]);
        $property->rateSeasons()->create([
            'name' => 'Hoogseizoen',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-31',
            'price_cents' => 20000,
            'weekend_price_cents' => 25000,
        ]);

        // Thu 2 to Sun 5 July: Thu night €200, Fri+Sat night €250
        $quote = $this->quote($property, '2026-07-02', '2026-07-05');

        $this->assertSame(70000, $quote->totalCents);
    }

    public function test_season_in_the_new_year(): void
    {
        $property = $this->makeProperty();
        $property->rateSeasons()->create([
            'name' => 'Kerstvakantie',
            'start_date' => '2026-12-20',
            'end_date' => '2027-01-05',
            'price_cents' => 30000,
        ]);

        // Wed 30 Dec to Sat 2 Jan = 3 nights, all in the season
        $quote = $this->quote($property, '2026-12-30', '2027-01-02');

        $this->assertSame(90000, $quote->totalCents);
    }

    public function test_cleaning_fee_and_tourist_tax(): void
    {
        $property = $this->makeProperty([
            'cleaning_fee_cents' => 7500,       // €75 one-time
            'tourist_tax_cents' => 250,         // €2.50 p.p. per night
        ]);

        // 2 nights, 4 guests: stay €200 + cleaning €75 + tax 4×2×€2.50 = €20
        $quote = $this->quote($property, '2026-03-02', '2026-03-04', 4);

        $this->assertSame(29500, $quote->totalCents);
        $this->assertSame(7500, $quote->line('cleaning')->amountCents);
        $this->assertSame(2000, $quote->line('tourist_tax')->amountCents);
    }

    public function test_surcharge_extra_guests(): void
    {
        $property = $this->makeProperty([
            'included_guests' => 2,
            'extra_guest_fee_cents' => 1500, // €15 p.p. per night
        ]);

        // 3 nights, 4 guests: 2 extra guests × €15 × 3 nights = €90
        $quote = $this->quote($property, '2026-03-02', '2026-03-05', 4);

        $this->assertSame(30000 + 9000, $quote->totalCents);
        $this->assertSame(9000, $quote->line('extra_guests')->amountCents);
    }

    public function test_discount_from_x_nights_highest_threshold_wins(): void
    {
        $property = $this->makeProperty();
        $property->discounts()->create(['min_nights' => 7, 'percentage' => 10]);
        $property->discounts()->create(['min_nights' => 14, 'percentage' => 20]);

        // 14 nights → 20%, not 10%. Stay 14 × €100 = €1400, discount €280.
        $quote = $this->quote($property, '2026-03-02', '2026-03-16');

        $this->assertSame(112000, $quote->totalCents);
        $this->assertSame(-28000, $quote->line('discount')->amountCents);
    }

    public function test_discount_applies_not_under_the_threshold(): void
    {
        $property = $this->makeProperty();
        $property->discounts()->create(['min_nights' => 7, 'percentage' => 10]);

        $quote = $this->quote($property, '2026-03-02', '2026-03-05');

        $this->assertNull($quote->line('discount'));
        $this->assertSame(30000, $quote->totalCents);
    }

    public function test_discount_applies_not_in_cleaning_and_tax(): void
    {
        $property = $this->makeProperty([
            'cleaning_fee_cents' => 5000,
            'tourist_tax_cents' => 100,
        ]);
        $property->discounts()->create(['min_nights' => 7, 'percentage' => 10]);

        // 7 nights, 2 guests: stay €700, discount €70, cleaning €50, tax €14
        $quote = $this->quote($property, '2026-03-02', '2026-03-09');

        $this->assertSame(70000 - 7000 + 5000 + 1400, $quote->totalCents);
    }

    public function test_discount_as_fixed_amount_applies_a_time_in_the_stay(): void
    {
        $property = $this->makeProperty(['cleaning_fee_cents' => 5000]);
        $property->discounts()->create(['min_nights' => 7, 'type' => 'fixed', 'amount_cents' => 5000]);

        // 7 nights × €100 = €700, €50 off (not per night), cleaning €50 stays.
        $quote = $this->quote($property, '2026-03-02', '2026-03-09');

        $this->assertSame(-5000, $quote->line('discount')->amountCents);
        $this->assertSame(70000 - 5000 + 5000, $quote->totalCents);
        $this->assertStringContainsString('€', $quote->line('discount')->label);
    }

    public function test_a_fixed_amount_is_never_more_then_the_stay(): void
    {
        $property = $this->makeProperty(['cleaning_fee_cents' => 5000]);
        $property->discounts()->create(['min_nights' => 2, 'type' => 'fixed', 'amount_cents' => 99999]);

        // 3 nights = €300 stay; the discount stops there, cleaning stays.
        $quote = $this->quote($property, '2026-03-02', '2026-03-05');

        $this->assertSame(-30000, $quote->line('discount')->amountCents);
        $this->assertSame(5000, $quote->totalCents);
    }

    public function test_highest_threshold_wins_also_between_percentage_and_fixed_amount(): void
    {
        $property = $this->makeProperty();
        $property->discounts()->create(['min_nights' => 7, 'percentage' => 10]);
        $property->discounts()->create(['min_nights' => 14, 'type' => 'fixed', 'amount_cents' => 25000]);

        $this->assertSame(-7000, $this->quote($property, '2026-03-02', '2026-03-09')->line('discount')->amountCents);
        $this->assertSame(-25000, $this->quote($property, '2026-03-02', '2026-03-16')->line('discount')->amountCents);
    }

    public function test_down_payment_and_balance_according_to_setting(): void
    {

        $quote = $this->quote($this->makeProperty(), '2026-03-02', '2026-03-05');

        $this->assertSame(9000, $quote->downPaymentCents);
        $this->assertSame(21000, $quote->remainingCents);
        $this->assertSame($quote->totalCents, $quote->downPaymentCents + $quote->remainingCents);
    }

    public function test_deposit_is_separate_of_the_total(): void
    {
        $property = $this->makeProperty(['deposit_cents' => 25000]);

        $quote = $this->quote($property, '2026-03-02', '2026-03-05');

        $this->assertSame(30000, $quote->totalCents);
        $this->assertSame(25000, $quote->depositCents);
    }

    public function test_departure_must_after_arrival_lie(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->quote($this->makeProperty(), '2026-03-05', '2026-03-05');
    }

    public function test_to_many_guests_gives_a_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->quote($this->makeProperty(['max_guests' => 4]), '2026-03-02', '2026-03-05', 5);
    }
}
