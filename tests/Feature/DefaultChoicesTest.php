<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ID verification and prices-in-calendar in three layers: the accommodation, then the
 * defaults, then the general setting.
 */
class DefaultChoicesTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(array $extra = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ], $extra));
    }

    public function test_the_accommodation_follows_the_default(): void
    {
        Property::master()->update(['id_required' => true]);

        $this->assertTrue($this->accommodation()->idRequired());
    }

    public function test_the_accommodation_goes_for_the_default(): void
    {
        Property::master()->update(['id_required' => true]);

        $this->assertFalse($this->accommodation(['id_required' => false])->idRequired());
    }

    public function test_a_new_default_is_off(): void
    {
        $this->assertFalse($this->accommodation()->idRequired());
        $this->assertFalse($this->accommodation(['slug' => 'other'])->pricesInCalendar());
    }

    public function test_price_in_calendar_works_on_same_way(): void
    {
        Property::master()->update(['prices_in_calendar' => true]);

        $this->assertTrue($this->accommodation()->pricesInCalendar());
        $this->assertFalse($this->accommodation(['slug' => 'other', 'prices_in_calendar' => false])->pricesInCalendar());
    }

    public function test_the_choice_list_is_on_the_defaults_and_is_saved(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(EditDefaults::class)
            ->assertFormFieldExists('id_required')
            ->assertFormFieldExists('prices_in_calendar')
            ->fillForm(['id_required' => '1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue((bool) Property::master()->fresh()->id_required);
    }
}
