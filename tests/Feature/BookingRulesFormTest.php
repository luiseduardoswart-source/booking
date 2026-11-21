<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The booking rules in the admin: a period has both dates or none (with one
 * date the rule would apply always, also before it), and the screen says that
 * own rules of an accommodation replace those of the default settings.
 */
class BookingRulesFormTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function saveRule(array $rule): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->owner())
            ->test(EditDefaults::class)
            ->fillForm(['bookingRules' => [['min_nights' => 7, ...$rule]]])
            ->call('save');
    }

    public function test_a_period_has_both_dates_or_none(): void
    {
        $restore = Repeater::fake();

        $this->saveRule(['start_date' => '2027-07-01'])->assertHasFormErrors(['bookingRules.0.end_date' => 'required_with']);
        $this->saveRule(['end_date' => '2027-08-31'])->assertHasFormErrors(['bookingRules.0.start_date' => 'required_with']);
        $this->assertSame(0, Property::master()->bookingRules()->count());

        $this->saveRule(['start_date' => '2027-07-01', 'end_date' => '2027-08-31'])->assertHasNoFormErrors();
        $this->saveRule([])->assertHasNoFormErrors();

        $restore();
    }

    public function test_the_screen_says_that_own_rules_replace_those_of_the_default(): void
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        Livewire::actingAs($owner = $this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertSee(__('Rules without a period always apply; rules with a period only for arrivals within that period. As soon as this accommodation has a rule of its own, the rules of the default settings no longer apply to it.'));

        Livewire::actingAs($owner)
            ->test(EditDefaults::class)
            ->assertSee(__('Rules without a period always apply; rules with a period only for arrivals within that period. They apply to every accommodation without booking rules of its own.'));
    }
}
