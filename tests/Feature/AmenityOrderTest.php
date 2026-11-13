<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Livewire\AccommodationAmenities;
use App\Models\Amenity;
use App\Models\Host;
use App\Models\Property;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The owner drags the amenities of an accommodation into their own order:
 * the first ones appear on the page, the window follows the same order
 * within each category.
 */
class AmenityOrderTest extends TestCase
{
    use RefreshDatabase;

    private Property $accommodation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);
    }

    private function id(string $name): int
    {
        return Amenity::query()->where('name', $name)->value('id');
    }

    /** @return array<int, string> */
    private function order(): array
    {
        return $this->accommodation->fresh()->amenities->pluck('name')->all();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_dragging_sets_the_order_and_the_page_follows_it(): void
    {
        $this->accommodation->syncAmenities([$this->id('Wifi'), $this->id('Kitchen'), $this->id('Air conditioning')]);

        Livewire::actingAs($this->owner())
            ->test(AccommodationAmenities::class, ['property' => $this->accommodation])
            ->call('move', $this->id('Air conditioning'), 0)
            ->assertSeeInOrder([__('Air conditioning'), __('Wifi'), __('Kitchen')]);

        $this->assertSame(['Air conditioning', 'Wifi', 'Kitchen'], $this->order());

        app()->setLocale('en');
        $this->get('/strandhuis')->assertOk()->assertSeeInOrder([__('Air conditioning'), __('Wifi'), __('Kitchen')]);
    }

    public function test_the_first_ten_are_on_the_page_whatever_their_id(): void
    {
        $ids = Amenity::query()->orderBy('id')->limit(Amenity::ON_THE_PAGE + 1)->pluck('id')->all();
        $last = end($ids);
        $this->accommodation->syncAmenities($ids);

        $this->accommodation->moveAmenity($last, 0);

        $this->assertSame($last, $this->accommodation->fresh()->amenities->take(Amenity::ON_THE_PAGE)->first()->id);
        $this->assertNotContains($ids[Amenity::ON_THE_PAGE - 1], $this->accommodation->fresh()->amenities->take(Amenity::ON_THE_PAGE)->modelKeys());
    }

    public function test_ticking_keeps_the_order_and_adds_new_ones_at_the_end(): void
    {
        $this->accommodation->syncAmenities([$this->id('Wifi'), $this->id('Kitchen'), $this->id('Air conditioning')]);
        $this->accommodation->moveAmenity($this->id('Air conditioning'), 0);

        // Kitchen off, Hair dryer on: Pool and Wifi stay in front, the new one comes last.
        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()])
            ->set('data.amenities', [(string) $this->id('Wifi'), (string) $this->id('Air conditioning'), (string) $this->id('Hair dryer')])
            ->assertDispatched(AccommodationAmenities::CHANGED);

        $this->assertSame(['Air conditioning', 'Wifi', 'Hair dryer'], $this->order());
    }

    public function test_the_window_follows_the_order_within_a_category(): void
    {
        $this->accommodation->syncAmenities([$this->id('Wifi'), $this->id('Shampoo'), $this->id('Hair dryer')]);
        $this->accommodation->moveAmenity($this->id('Hair dryer'), 0);

        app()->setLocale('nl');
        $groups = Amenity::perCategory($this->accommodation->fresh()->amenities);

        $this->assertSame(['Haardroger', 'Shampoo'], $groups[__('Bathroom')]->map->localizedName()->values()->all());
    }

    public function test_an_amenity_that_is_not_chosen_can_not_be_moved_in(): void
    {
        $this->accommodation->syncAmenities([$this->id('Wifi')]);

        $this->accommodation->moveAmenity($this->id('Air conditioning'), 0);

        $this->assertSame(['Wifi'], $this->order());
    }

    public function test_a_host_of_another_accommodation_can_not_reorder(): void
    {
        $this->accommodation->syncAmenities([$this->id('Wifi'), $this->id('Air conditioning')]);
        $host = Host::create(['name' => 'Vreemd']);
        $host->properties()->attach(Property::create([
            'name' => 'Stadshuis', 'slug' => 'stadshuis', 'type' => 'holiday_home', 'max_guests' => 2,
            'active' => true, 'base_price_cents' => 10000,
        ]));
        $stranger = User::factory()->create([
            'role' => UserRole::Host, 'host_id' => $host->id, 'permissions' => ['accommodations' => Access::ACTIONS],
        ]);

        Livewire::actingAs($stranger)
            ->test(AccommodationAmenities::class, ['property' => $this->accommodation])
            ->call('move', $this->id('Air conditioning'), 0)
            ->assertForbidden();

        $this->assertSame(['Wifi', 'Air conditioning'], $this->order());
    }

    public function test_the_order_list_is_on_the_edit_page(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()])
            ->assertSeeLivewire(AccommodationAmenities::class);
    }
}
