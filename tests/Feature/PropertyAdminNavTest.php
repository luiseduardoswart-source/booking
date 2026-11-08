<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\CalendarBlocks\CalendarBlockResource;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PropertyAdminNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_is_a_tab_in_the_accommodation_form(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator, 'locale' => 'nl']);
        $property = Property::create(['name' => 'Tabhuis', 'slug' => 'tabhuis', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true]);

        $this->actingAs($admin)
            ->get(PropertyResource::getUrl('edit', ['record' => $property]))
            ->assertOk()
            ->assertSee('Kalender');
    }

    public function test_calendar_menu_item_is_hidden(): void
    {
        $this->assertFalse(CalendarBlockResource::shouldRegisterNavigation());
    }

    public function test_save_works_still(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $property = Property::create(['name' => 'Oud', 'slug' => 'old', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true, 'country' => 'NL', 'cancellation_policy' => 'moderate']);

        Livewire::actingAs($admin)
            ->test(EditProperty::class, ['record' => $property->getRouteKey()])
            ->fillForm(['name' => 'Nieuw'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nieuw', $property->fresh()->name);
    }
}
