<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Amenities\Pages\ManageAmenities;
use App\Models\Amenity;
use App\Models\User;
use App\Support\Access;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adding amenities in one go: a list with one name per line.
 */
class AmenitiesBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Amenity::query()->delete();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_a_list_adds_only_new_names(): void
    {
        Amenity::create(['name' => 'Sauna']);

        Livewire::actingAs($this->owner())->test(ManageAmenities::class)
            ->callAction('multipleAdd', ['names' => "WiFi\r\n  Jacuzzi  \n\nsauna\nJACUZZI\nBuitendouche"])
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing(['Sauna', 'WiFi', 'Jacuzzi', 'Buitendouche'], Amenity::pluck('name')->all());
        Notification::assertNotified(trans_choice('{0} No new amenities added.|{1} 1 amenity added.|[2,*] :count amenities added.', 3));
    }

    public function test_the_icon_is_on_name_recognized(): void
    {
        Livewire::actingAs($this->owner())->test(ManageAmenities::class)
            ->callAction('multipleAdd', ['names' => "Wifi\nZeilboot"]);

        $this->assertSame(Amenity::DEFAULT['Wifi'], Amenity::where('name', 'Wifi')->first()->iconName());
        $this->assertSame('check', Amenity::where('name', 'Zeilboot')->first()->iconName());
    }

    public function test_a_to_long_name_is_rejected(): void
    {
        Livewire::actingAs($this->owner())->test(ManageAmenities::class)
            ->assertActionVisible('multipleAdd')
            ->callAction('multipleAdd', ['names' => "Jacuzzi\n".str_repeat('a', Amenity::MAX_NAME + 1)])
            ->assertHasActionErrors(['names']);

        $this->assertSame(0, Amenity::count());
    }

    public function test_without_permission_to_on_to_make_no_button(): void
    {
        $onlyLook = User::factory()->create([
            'role' => UserRole::Staff,
            'permissions' => ['amenities' => [Access::VIEW]],
        ]);

        Livewire::actingAs($onlyLook)->test(ManageAmenities::class)
            ->assertActionHidden('multipleAdd');
    }
}
