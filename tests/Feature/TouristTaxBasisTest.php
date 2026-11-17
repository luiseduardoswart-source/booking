<?php

namespace Tests\Feature;

use App\Engines\PricingEngine;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tourist tax per person per night, per night or once per stay; per
 * accommodation or as the default. And the prices fields stand in the same
 * order on an accommodation and on the default settings.
 */
class TouristTaxBasisTest extends TestCase
{
    use RefreshDatabase;

    /** Base price and weekend; cleaning and tourist tax; guests and pets. */
    private const PRICES_ORDER = [
        'base_price_cents', 'weekend_kind', 'weekend_percentage',
        'cleaning_fee_cents', 'tourist_tax_cents', 'tourist_tax_basis',
        'included_guests', 'extra_guest_fee_cents', 'pet_fee_cents',
    ];

    private int $houses = 0;

    private function accommodation(array $extra = []): Property
    {
        $this->houses++;

        return Property::create([
            'name' => "Huis {$this->houses}", 'slug' => "house-{$this->houses}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, ...$extra,
        ]);
    }

    /** Two nights with three guests. */
    private function touristTax(Property $accommodation): ?int
    {
        return app(PricingEngine::class)->quote(
            $accommodation, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-04'), 3,
        )->line('tourist_tax')?->amountCents;
    }

    public function test_per_person_per_night_stays_the_default(): void
    {
        $this->assertSame(Property::TAX_PER_PERSON_NIGHT, Property::master()->tourist_tax_basis);

        $this->assertSame(3 * 2 * 6800, $this->touristTax($this->accommodation(['tourist_tax_cents' => 6800])));
    }

    public function test_once_per_stay(): void
    {
        $accommodation = $this->accommodation(['tourist_tax_cents' => 6800, 'tourist_tax_basis' => Property::TAX_PER_STAY]);

        $this->assertSame(6800, $this->touristTax($accommodation));
    }

    public function test_per_night(): void
    {
        $accommodation = $this->accommodation(['tourist_tax_cents' => 6800, 'tourist_tax_basis' => Property::TAX_PER_NIGHT]);

        $this->assertSame(2 * 6800, $this->touristTax($accommodation));
    }

    public function test_an_accommodation_without_its_own_choice_follows_the_default(): void
    {
        Property::master()->update(['tourist_tax_cents' => 6800, 'tourist_tax_basis' => Property::TAX_PER_STAY]);

        $this->assertSame(6800, $this->touristTax($this->accommodation()));
    }

    public function test_the_basis_can_be_chosen_on_the_default_settings_and_on_an_accommodation(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($administrator)
            ->test(EditDefaults::class)
            ->fillForm(['tourist_tax_basis' => Property::TAX_PER_STAY])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Property::TAX_PER_STAY, Property::master()->fresh()->tourist_tax_basis);

        Livewire::actingAs($administrator)
            ->test(EditDefaults::class)
            ->fillForm(['tourist_tax_basis' => null])
            ->call('save')
            ->assertHasFormErrors(['tourist_tax_basis' => 'required']);

        $accommodation = $this->accommodation();

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['tourist_tax_basis' => Property::TAX_PER_NIGHT])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Property::TAX_PER_NIGHT, $accommodation->fresh()->tourist_tax_basis);
    }

    /** The visible prices fields, in the order they stand in the form. */
    private function pricesFields($component): array
    {
        $fields = [];

        // Keys as "prices.base_price_cents": the Prices section has a fixed key.
        foreach (array_keys($component->instance()->form->getFlatFields()) as $key) {
            $name = str($key)->after('prices.')->toString();

            if (str_starts_with($key, 'prices.') && in_array($name, self::PRICES_ORDER, true)) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    public function test_the_prices_fields_stand_in_the_same_order_on_an_accommodation_and_the_defaults(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        Property::master()->update(['weekend_kind' => Property::WEEKEND_PERCENT, 'weekend_percentage' => 10]);

        // An accommodation that follows the default weekend adjustment.
        $accommodation = $this->accommodation();

        $defaults = $this->pricesFields(Livewire::actingAs($administrator)->test(EditDefaults::class));
        $own = $this->pricesFields(Livewire::actingAs($administrator)->test(EditProperty::class, ['record' => $accommodation->getKey()]));

        $this->assertSame(self::PRICES_ORDER, $defaults);
        $this->assertSame(self::PRICES_ORDER, $own);
    }

    public function test_following_the_default_weekend_adjustment_shows_its_value_without_saving_it(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        Property::master()->update(['weekend_kind' => Property::WEEKEND_PERCENT, 'weekend_percentage' => 10]);
        $accommodation = $this->accommodation();

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertFormFieldIsDisabled('prices.weekend_percentage')
            ->assertSee('placeholder="10"', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($accommodation->fresh()->weekend_kind);
        $this->assertNull($accommodation->fresh()->weekend_percentage);
    }

    public function test_choosing_follow_the_default_shows_the_default_weekend_price_not_the_own_one(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        Property::master()->update(['weekend_kind' => Property::WEEKEND_FIXED, 'weekend_price_cents' => 13000]);
        $accommodation = $this->accommodation(['weekend_kind' => Property::WEEKEND_FIXED, 'weekend_price_cents' => 14000]);

        Livewire::actingAs($administrator)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertFormFieldIsEnabled('prices.weekend_price_cents')
            ->fillForm(['weekend_kind' => null])
            ->assertFormSet(['weekend_price_cents' => null])
            ->assertFormFieldIsDisabled('prices.weekend_price_cents')
            ->assertSee('placeholder="130"', false);
    }
}

