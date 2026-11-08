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
 * The accommodation page says how the guest checks in: by themselves, or
 * welcomed in person by one of the hosts (for example the owner or a family
 * member), with the name of that host.
 */
class CheckInKindTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function accommodation(array $extra = []): Property
    {
        static $counter = 0;
        $counter++;

        return Property::create([
            'name' => 'Strandhuis', 'slug' => "strandhuis-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'check_in_from' => '15:00', 'check_out_until' => '11:00', ...$extra,
        ]);
    }

    private function page(Property $accommodation)
    {
        return $this->get(route('properties.show', $accommodation->slug))->assertOk();
    }

    public function test_by_default_the_guest_checks_in_by_themselves(): void
    {
        $this->page($this->accommodation())
            ->assertSee(__('Self check-in'))
            ->assertSee(__('Check-in from :time', ['time' => '15:00']));
    }

    public function test_the_host_who_welcomes_the_guest_is_named(): void
    {
        $maria = Host::create(['name' => 'Maria']);

        $this->page($this->accommodation(['check_in_kind' => 'welcome', 'check_in_host_id' => $maria->id]))
            ->assertSee(__(':name welcomes you', ['name' => 'Maria']))
            ->assertDontSee(__('Self check-in'))
            ->assertSee(__('Check-in from :time', ['time' => '15:00']));
    }

    public function test_without_a_chosen_host_the_welcome_is_general(): void
    {
        $this->page($this->accommodation(['check_in_kind' => 'welcome']))
            ->assertSee(__('You are welcomed in person'))
            ->assertDontSee(__('Self check-in'));
    }

    public function test_an_accommodation_follows_the_welcome_of_the_default(): void
    {
        $luis = Host::create(['name' => 'Luis']);
        Property::master()->update(['check_in_kind' => 'welcome', 'check_in_host_id' => $luis->id]);

        $follows = $this->accommodation();
        $own = $this->accommodation(['check_in_kind' => 'self']);

        $this->assertSame('Luis', $follows->checkInHost()?->name);
        $this->assertNull($own->checkInHost());
        $this->page($follows)->assertSee(__(':name welcomes you', ['name' => 'Luis']));
        $this->page($own)->assertSee(__('Self check-in'));
    }

    public function test_a_removed_host_leaves_a_general_welcome(): void
    {
        $maria = Host::create(['name' => 'Maria']);
        $accommodation = $this->accommodation(['check_in_kind' => 'welcome', 'check_in_host_id' => $maria->id]);

        $maria->delete();

        $this->page($accommodation)->assertSee(__('You are welcomed in person'))->assertDontSee('Maria');
    }

    public function test_self_check_in_forgets_the_host(): void
    {
        $maria = Host::create(['name' => 'Maria']);
        $accommodation = $this->accommodation(['check_in_kind' => 'welcome', 'check_in_host_id' => $maria->id]);

        $accommodation->update(['check_in_kind' => 'self']);

        $this->assertNull($accommodation->refresh()->check_in_host_id);
    }

    public function test_a_new_host_can_be_added_right_from_the_check_in_choice(): void
    {
        $accommodation = $this->accommodation();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['check_in_kind' => 'welcome'])
            ->callAction(
                \Filament\Actions\Testing\TestAction::make('createOption')->schemaComponent('check_in_host_id', schema: 'form'),
                data: ['name' => 'Oma Rosa'],
            )
            ->assertHasNoFormErrors()
            ->call('save')
            ->assertHasNoFormErrors();

        $rosa = Host::query()->where('name', 'Oma Rosa')->sole();
        $this->assertSame($rosa->id, $accommodation->refresh()->check_in_host_id);
        $this->page($accommodation)->assertSee(__(':name welcomes you', ['name' => 'Oma Rosa']));
    }

    public function test_the_welcome_is_saved_from_the_accommodation_screen(): void
    {
        $maria = Host::create(['name' => 'Maria']);
        $accommodation = $this->accommodation();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['check_in_kind' => 'welcome'])
            ->assertSee('Maria')
            ->fillForm(['check_in_host_id' => $maria->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame('welcome', $accommodation->check_in_kind);
        $this->assertSame($maria->id, $accommodation->check_in_host_id);
    }
}
