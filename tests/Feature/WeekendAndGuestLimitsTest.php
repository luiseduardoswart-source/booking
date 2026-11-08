<?php

namespace Tests\Feature;

use App\Engines\PricingEngine;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Weekend adjustment as a fixed amount or as a percentage of the nightly price, and
 * a maximum number of babies and pets; per accommodation or as the default.
 */
class WeekendAndGuestLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(array $extra = []): Property
    {
        static $counter = 0;
        $counter++;

        return Property::create([
            'name' => "Huis {$counter}", 'slug' => "house-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, ...$extra,
        ]);
    }

    /** Thursday to Sunday: one weekday night and two weekend nights. */
    private function thursdayUntilSunday(Property $accommodation): int
    {
        return app(PricingEngine::class)->quote(
            $accommodation, CarbonImmutable::parse('2026-03-05'), CarbonImmutable::parse('2026-03-08'), 2,
        )->line('accommodation')->amountCents;
    }

    public function test_a_percentage_on_the_base_price_for_friday_and_saturday(): void
    {
        $this->assertSame(10000 + 11500 + 11500, $this->thursdayUntilSunday($this->accommodation(['weekend_kind' => 'percent', 'weekend_percentage' => 15])));
        $this->assertSame(10000 + 9000 + 9000, $this->thursdayUntilSunday($this->accommodation(['weekend_kind' => 'percent', 'weekend_percentage' => -10])));
    }

    public function test_a_fixed_amount_left_empty_uses_the_base_price(): void
    {
        Property::master()->update(['weekend_price_cents' => 99900]);

        $this->assertSame(10000 + 2 * 14000, $this->thursdayUntilSunday($this->accommodation(['weekend_kind' => 'fixed', 'weekend_price_cents' => 14000])));
        // Chose "fixed" itself without an amount: the own base price, not that of the default.
        $this->assertSame(30000, $this->thursdayUntilSunday($this->accommodation(['weekend_kind' => 'fixed'])));
    }

    public function test_the_percentage_also_applies_in_a_season_without_its_own_weekend_price(): void
    {
        $accommodation = $this->accommodation(['weekend_kind' => 'percent', 'weekend_percentage' => 10]);
        $accommodation->rateSeasons()->create(['name' => 'Voorjaar', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'price_cents' => 20000]);

        $this->assertSame(20000 + 22000 + 22000, $this->thursdayUntilSunday($accommodation));

        // A season's own weekend price takes precedence.
        $accommodation->rateSeasons()->first()->update(['weekend_price_cents' => 25000]);
        $this->assertSame(20000 + 25000 + 25000, $this->thursdayUntilSunday($accommodation));
    }

    public function test_without_its_own_choice_the_default_weekend_adjustment_applies(): void
    {
        Property::master()->update(['weekend_kind' => 'percent', 'weekend_percentage' => 20]);

        $this->assertSame(10000 + 12000 + 12000, $this->thursdayUntilSunday($this->accommodation()));
    }

    public function test_existing_weekend_prices_keep_working(): void
    {
        // As in the demo data: a weekend price without a kind counts as a fixed amount.
        $accommodation = $this->accommodation(['weekend_price_cents' => 14500]);
        $this->assertSame(Property::WEEKEND_FIXED, $accommodation->fresh()->weekend_kind);

        // And whoever follows the default gets the default's weekend price, as always.
        Property::master()->update(['weekend_price_cents' => 13000]);
        $this->assertSame(10000 + 2 * 13000, $this->thursdayUntilSunday($this->accommodation()));
    }

    public function test_deliberately_following_the_default_clears_the_own_weekend_values(): void
    {
        $accommodation = $this->accommodation(['weekend_kind' => 'fixed', 'weekend_price_cents' => 14000]);

        $accommodation->update(['weekend_kind' => null]);

        $this->assertNull($accommodation->fresh()->weekend_price_cents);
        $this->assertNull($accommodation->fresh()->weekend_kind);

        // From percentage to fixed: the old percentage does not linger.
        $accommodation->update(['weekend_kind' => 'percent', 'weekend_percentage' => 10]);
        $accommodation->update(['weekend_kind' => 'fixed', 'weekend_price_cents' => 12000]);
        $this->assertNull($accommodation->fresh()->weekend_percentage);
    }

    public function test_the_weekend_adjustment_can_be_chosen_in_the_admin(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $accommodation = $this->accommodation();

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['weekend_kind' => 'percent', 'weekend_percentage' => 15])
            ->assertSee('115,00')
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame('percent', $accommodation->weekend_kind);
        $this->assertSame(15, $accommodation->weekend_percentage);

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['weekend_kind' => 'percent', 'weekend_percentage' => 150])
            ->call('save')
            ->assertHasFormErrors(['weekend_percentage' => 'max']);

        Livewire::actingAs($administrator)
            ->test(EditDefaults::class)
            ->fillForm(['weekend_kind' => null])
            ->call('save')
            ->assertHasFormErrors(['weekend_kind' => 'required']);
    }

    /** Book via the site's form. */
    private function book(Property $accommodation, array $fields)
    {
        return $this->post("/{$accommodation->slug}/book", [
            'arrival' => today()->addDays(10)->toDateString(),
            'departure' => today()->addDays(13)->toDateString(),
            'adults' => 2,
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
            ...$fields,
        ]);
    }

    public function test_no_more_babies_and_pets_than_allowed(): void
    {
        Mail::fake();
        $this->app->instance(PaymentProvider::class, new FakeProvider);

        // Default: 5 babies and 2 pets.
        $accommodation = $this->accommodation(['pets_allowed' => true]);
        $this->book($accommodation, ['infants' => 6])->assertSessionHasErrors('infants');
        $this->book($accommodation, ['pets' => 3])->assertSessionHasErrors('pets');
        $this->assertSame(0, Booking::query()->count());

        // Own limits per accommodation.
        $roomy = $this->accommodation(['pets_allowed' => true, 'max_pets' => 4, 'max_babies' => 1]);
        $this->book($roomy, ['infants' => 2])->assertSessionHasErrors('infants');
        $this->book($roomy, ['infants' => 1, 'pets' => 4])->assertSessionHasNoErrors();
        $this->assertSame(4, Booking::query()->sole()->pets);
    }

    public function test_the_guest_picker_follows_the_limits_of_the_accommodation(): void
    {
        $this->withoutVite();
        $accommodation = $this->accommodation(['pets_allowed' => true, 'max_pets' => 4, 'max_babies' => 1]);

        $this->get("/{$accommodation->slug}")
            ->assertOk()
            ->assertSee('data-max-babies="1"', false)
            ->assertSee('data-max-pets="4"', false);

        // Pets not allowed: 0, whatever the maximum is.
        $accommodation->update(['pets_allowed' => false]);
        $this->get("/{$accommodation->slug}")->assertSee('data-max-pets="0"', false);
    }

    public function test_the_maximums_can_be_set_in_the_admin(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $accommodation = $this->accommodation();

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['pets_allowed' => '1', 'max_pets' => 3, 'max_babies' => 2])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame(3, $accommodation->maxPets());
        $this->assertSame(2, $accommodation->maxBabies());
    }
}
