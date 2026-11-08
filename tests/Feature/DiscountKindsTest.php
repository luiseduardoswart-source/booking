<?php

namespace Tests\Feature;

use App\Engines\PricingEngine;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Discount;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Discounts as on Airbnb: length of stay (week, month), last minute and
 * early booking, with a name for the guest. One discount per booking: the
 * most favourable.
 */
class DiscountKindsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-03-01 10:00:00'); // booked today
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function accommodation(): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, // €100 per night
        ]);
    }

    private function quote(Property $accommodation, string $arrival, int $nights)
    {
        $from = CarbonImmutable::parse($arrival);

        return app(PricingEngine::class)->quote($accommodation, $from, $from->addDays($nights), 2);
    }

    public function test_lastminute_applies_only_short_for_arrival(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->discounts()->create(['kind' => Discount::LASTMINUTE, 'days_before_arrival' => 14, 'percentage' => 10]);

        // 14 days ahead: discount. 15 days: none.
        $this->assertSame(-3000, $this->quote($accommodation, '2026-03-15', 3)->line('discount')->amountCents);
        $this->assertNull($this->quote($accommodation, '2026-03-16', 3)->line('discount'));
        // The day itself counts too.
        $this->assertNotNull($this->quote($accommodation, '2026-03-01', 3)->line('discount'));
    }

    public function test_early_booking_applies_only_long_of_in_advance(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->discounts()->create(['kind' => Discount::EARLY_BOOKING, 'months_before_arrival' => 2, 'percentage' => 5]);

        // Exactly 2 months: discount. Arriving a day earlier: none.
        $this->assertSame(-1500, $this->quote($accommodation, '2026-05-01', 3)->line('discount')->amountCents);
        $this->assertNull($this->quote($accommodation, '2026-04-30', 3)->line('discount'));
    }

    public function test_per_booking_applies_the_cheapest_discount(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->discounts()->create(['kind' => Discount::STAY_DURATION, 'min_nights' => 7, 'percentage' => 5]);
        $accommodation->discounts()->create(['kind' => Discount::STAY_DURATION, 'min_nights' => 28, 'percentage' => 8]);
        $early = $accommodation->discounts()->create(['kind' => Discount::EARLY_BOOKING, 'months_before_arrival' => 2, 'percentage' => 2]);

        // One week, far ahead: weekly discount 5% beats early booking 2%. Not added up.
        $this->assertSame(-3500, $this->quote($accommodation, '2026-06-01', 7)->line('discount')->amountCents);

        // One month: the highest threshold (8%) counts, not the week.
        $this->assertSame(-22400, $this->quote($accommodation, '2026-06-01', 28)->line('discount')->amountCents);

        // If early booking becomes more favourable, the guest gets that one.
        $early->update(['percentage' => 10]);
        $this->assertSame(-7000, $this->quote($accommodation, '2026-06-01', 7)->line('discount')->amountCents);
    }

    /** One week far ahead: weekly discount 5% and early booking 2% both apply. */
    private function weekAndEarlyBooking(Property $accommodation): void
    {
        $accommodation->discounts()->create(['kind' => Discount::STAY_DURATION, 'min_nights' => 7, 'percentage' => 5]);
        $accommodation->discounts()->create(['kind' => Discount::EARLY_BOOKING, 'months_before_arrival' => 2, 'percentage' => 2]);
    }

    public function test_with_add_up_on_gets_the_guest_all_discounts_that_apply(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->update(['discounts_add_up' => true]);
        $this->weekAndEarlyBooking($accommodation);

        $quote = $this->quote($accommodation, '2026-06-01', 7); // €700

        $rules = collect($quote->lines)->where('key', 'discount');
        $this->assertSame([-3500, -1400], $rules->pluck('amountCents')->values()->all());
        $this->assertSame(70000 - 3500 - 1400, $quote->totalCents);
    }

    public function test_add_up_follows_the_default_unless_the_accommodation_itself_chooses(): void
    {
        Property::master()->update(['discounts_add_up' => true]);
        $accommodation = $this->accommodation();
        $this->weekAndEarlyBooking($accommodation);

        // Empty: the default (add up).
        $this->assertSame(70000 - 4900, $this->quote($accommodation, '2026-06-01', 7)->totalCents);

        // Own choice "off": only the most favourable.
        $accommodation->update(['discounts_add_up' => false]);
        $this->assertSame(70000 - 3500, $this->quote($accommodation->fresh(), '2026-06-01', 7)->totalCents);
    }

    public function test_summed_discounts_are_never_more_then_the_stay(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->update(['discounts_add_up' => true, 'cleaning_fee_cents' => 5000]);
        $accommodation->discounts()->create(['kind' => Discount::STAY_DURATION, 'min_nights' => 2, 'percentage' => 70]);
        $accommodation->discounts()->create(['kind' => Discount::LASTMINUTE, 'days_before_arrival' => 30, 'percentage' => 60]);

        $quote = $this->quote($accommodation, '2026-03-05', 3); // €300 stay

        // 70% (€210) + the rest up to €300 (€90), not €180; cleaning stays.
        $this->assertSame([-21000, -9000], collect($quote->lines)->where('key', 'discount')->pluck('amountCents')->values()->all());
        $this->assertSame(5000, $quote->totalCents);
    }

    public function test_add_up_choose_you_in_the_admin(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $accommodation = $this->accommodation();

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['discounts_add_up' => '1'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($accommodation->fresh()->discountsAddUp());

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['discounts_add_up' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($accommodation->fresh()->discounts_add_up);

        // On the defaults screen a choice is required.
        Livewire::actingAs($administrator)
            ->test(EditDefaults::class)
            ->fillForm(['discounts_add_up' => null])
            ->call('save')
            ->assertHasFormErrors(['discounts_add_up' => 'required']);
    }

    public function test_the_guest_sees_the_name_of_the_discount_in_are_language(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->discounts()->create([
            'kind' => Discount::STAY_DURATION, 'min_nights' => 7, 'percentage' => 10,
            'name' => 'Weekly discount', 'name_translations' => ['nl' => 'Weekkorting'],
        ]);
        $accommodation->discounts()->create(['kind' => Discount::LASTMINUTE, 'days_before_arrival' => 3, 'percentage' => 20]);

        app()->setLocale('nl');
        $this->assertSame('Weekkorting (10%)', $this->quote($accommodation, '2026-04-01', 7)->line('discount')->label);
        $this->assertSame('Lastminutekorting 20%', $this->quote($accommodation, '2026-03-02', 2)->line('discount')->label);

        app()->setLocale('en');
        $this->assertSame('Weekly discount (10%)', $this->quote($accommodation, '2026-04-01', 7)->line('discount')->label);
    }

    public function test_at_a_other_kind_stays_no_old_threshold_hang(): void
    {
        $discount = $this->accommodation()->discounts()->create(['min_nights' => 7, 'percentage' => 10]);
        $this->assertSame(Discount::STAY_DURATION, $discount->fresh()->kind);

        $discount->update(['kind' => Discount::LASTMINUTE, 'days_before_arrival' => 7]);

        $this->assertNull($discount->fresh()->min_nights);
        $this->assertSame(7, $discount->fresh()->days_before_arrival);
    }

    public function test_kind_name_and_threshold_are_via_the_form_saved(): void
    {
        $restore = Repeater::fake();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditDefaults::class)
            ->fillForm(['discounts' => [
                ['name' => 'Last minute', 'kind' => Discount::LASTMINUTE, 'days_before_arrival' => 14, 'type' => Discount::TYPE_PERCENT, 'percentage' => 10],
                ['kind' => Discount::EARLY_BOOKING, 'months_before_arrival' => null, 'type' => Discount::TYPE_PERCENT, 'percentage' => 2],
            ]])
            ->call('save')
            ->assertHasFormErrors(['discounts.1.months_before_arrival' => 'required']);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditDefaults::class)
            ->fillForm(['discounts' => [
                ['name' => 'Last minute', 'kind' => Discount::LASTMINUTE, 'days_before_arrival' => 14, 'type' => Discount::TYPE_PERCENT, 'percentage' => 10],
                ['kind' => Discount::EARLY_BOOKING, 'months_before_arrival' => 2, 'type' => Discount::TYPE_PERCENT, 'percentage' => 2],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $restore();

        $discounts = Property::master()->discounts()->orderBy('id')->get();
        $this->assertSame([Discount::LASTMINUTE, Discount::EARLY_BOOKING], $discounts->pluck('kind')->all());
        $this->assertSame('Last minute', $discounts[0]->name);
        $this->assertSame(14, $discounts[0]->days_before_arrival);
        $this->assertSame(2, $discounts[1]->months_before_arrival);
    }

    public function test_discounts_are_above_the_seasons(): void
    {
        $html = Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->html();

        $heading = fn (string $title) => preg_match('/fi-section-header-heading[^>]*>\s*'.preg_quote($title, '/').'\s*</', $html, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;

        $this->assertNotNull($heading(__('Discounts')));
        $this->assertNotNull($heading(__('Seasons')));
        $this->assertLessThan($heading(__('Seasons')), $heading(__('Discounts')));

        // No separate English heading of the list itself ("Discounts", "Rate seasons").
        $this->assertDoesNotMatchRegularExpression('/>\s*(Discounts|Rate seasons)\s*</', $html);
    }
}
