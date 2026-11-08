<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Discount;
use App\Models\Property;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A discount from X nights can be a percentage or a fixed amount, both on
 * the default property and on a property itself.
 */
class DiscountFixedAmountTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function accommodation(): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    public function test_a_fixed_amount_is_at_the_defaults_in_cents_saved(): void
    {
        $restore = Repeater::fake();

        Livewire::actingAs($this->administrator())
            ->test(EditDefaults::class)
            ->fillForm(['discounts' => [
                ['kind' => Discount::STAY_DURATION, 'min_nights' => 7, 'type' => Discount::TYPE_FIXED, 'amount_cents' => 50.5],
                ['kind' => Discount::STAY_DURATION, 'min_nights' => 14, 'type' => Discount::TYPE_PERCENT, 'percentage' => 15],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $restore();

        $discounts = Property::master()->discounts()->orderBy('min_nights')->get();
        $this->assertSame([Discount::TYPE_FIXED, Discount::TYPE_PERCENT], $discounts->pluck('type')->all());
        $this->assertSame(5050, $discounts[0]->amount_cents);
        $this->assertNull($discounts[0]->percentage);
        $this->assertSame(15, $discounts[1]->percentage);
        $this->assertNull($discounts[1]->amount_cents);
    }

    public function test_a_fixed_amount_can_also_at_a_accommodation_itself(): void
    {
        $accommodation = $this->accommodation();
        $restore = Repeater::fake();

        Livewire::actingAs($this->administrator())
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['discounts' => [
                ['kind' => Discount::STAY_DURATION, 'min_nights' => 5, 'type' => Discount::TYPE_FIXED, 'amount_cents' => 25],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $restore();

        $discount = $accommodation->discounts()->sole();
        $this->assertSame(Discount::TYPE_FIXED, $discount->type);
        $this->assertSame(2500, $discount->amount_cents);
    }

    public function test_at_a_fixed_amount_is_the_amount_required(): void
    {
        $restore = Repeater::fake();

        Livewire::actingAs($this->administrator())
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->fillForm(['discounts' => [
                ['kind' => Discount::STAY_DURATION, 'min_nights' => 5, 'type' => Discount::TYPE_FIXED, 'amount_cents' => null],
            ]])
            ->call('save')
            ->assertHasFormErrors(['discounts.0.amount_cents' => 'required']);

        $restore();
    }

    public function test_switch_of_kind_lets_no_old_value_behind(): void
    {
        $discount = $this->accommodation()->discounts()->create(['min_nights' => 7, 'percentage' => 10]);

        $discount->update(['type' => Discount::TYPE_FIXED, 'amount_cents' => 4000]);
        $this->assertNull($discount->fresh()->percentage);

        $discount->update(['type' => Discount::TYPE_PERCENT, 'percentage' => 12]);
        $this->assertNull($discount->fresh()->amount_cents);
        $this->assertSame(12, $discount->fresh()->percentage);
    }

    public function test_existing_discounts_stay_a_percentage(): void
    {
        $discount = $this->accommodation()->discounts()->create(['min_nights' => 7, 'percentage' => 10]);

        $this->assertSame(Discount::TYPE_PERCENT, $discount->fresh()->type);
        $this->assertSame('10%', $discount->fresh()->discountLabel());
    }
}
