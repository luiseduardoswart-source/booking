<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Host;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Location and host belong to the property: they are on the first tab of the
 * accommodation form and are simply saved from there.
 */
class AccommodationFormTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);
    }

    public function test_location_and_host_have_no_own_tab_more(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $this->accommodation()->getRouteKey()])
            ->assertDontSee(__('Locatie & host'))
            ->assertSee(__('Location'))
            ->assertFormFieldExists('address')
            ->assertFormFieldExists('hosts');
    }

    public function test_address_and_host_are_from_the_first_tab_saved(): void
    {
        $accommodation = $this->accommodation();
        $host = Host::create(['name' => 'Sarit']);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->fillForm(['address' => 'Strandweg 1', 'city' => 'Cartagena', 'country' => 'CO', 'hosts' => [$host->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();

        $this->assertSame('Strandweg 1', $accommodation->address);
        $this->assertSame('Cartagena', $accommodation->city);
        $this->assertSame('CO', $accommodation->country);
        $this->assertEquals([$host->id], $accommodation->hosts->modelKeys());
    }
}
