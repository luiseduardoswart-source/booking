<?php

namespace Tests\Feature;

use App\Enums\AdminNotification;
use App\Enums\UserRole;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Hosts\Pages\ManageHosts;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\ListProperties;
use App\Models\Booking;
use App\Models\Host;
use App\Models\Property;
use App\Models\Review;
use App\Models\User;
use App\Support\AdminNotifications;
use App\Support\Access;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An accommodation can have several hosts and a host several accommodations.
 * Being linked gives access according to the user's permissions; whoever is not
 * linked sees nothing, and a host does not change the links themselves.
 */
class MultipleHostsTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(string $name = 'Strandhuis'): Property
    {
        return Property::create([
            'name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);
    }

    private function booking(Property $accommodation): Booking
    {
        return Booking::create([
            'booking_number' => 'BK-'.uniqid(), 'property_id' => $accommodation->id,
            'status' => 'confirmed', 'payment_status' => 'unpaid',
            'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
            'arrival_date' => '2026-12-01', 'departure_date' => '2026-12-03', 'guests' => 2,
            'total_cents' => 32000, 'down_payment_cents' => 9600, 'deposit_cents' => 5000,
            'price_snapshot' => ['lines' => []],
        ]);
    }

    private function reviewWithReply(Property $accommodation): void
    {
        Review::create([
            'booking_id' => $this->booking($accommodation)->id, 'property_id' => $accommodation->id,
            'guest_name' => 'Jan', 'rating' => 5, 'comment' => 'Mooi',
            'status' => 'approved', 'host_reply' => 'Dank je!', 'host_replied_at' => now(),
        ]);
    }

    private function hostUser(Host $host, array $permissions = ['bookings' => ['view'], 'accommodations' => Access::ACTIONS, 'hosts' => Access::ACTIONS]): User
    {
        $user = User::factory()->create(['role' => UserRole::Host, 'host_id' => $host->id, 'permissions' => $permissions]);
        $user->forceFill(['password_set_at' => now()])->save();

        return $user;
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_on_the_accommodation_choose_you_multiple_hosts(): void
    {
        $accommodation = $this->accommodation();
        $hosts = [Host::create(['name' => 'Sarit'])->id, Host::create(['name' => 'Luis'])->id];

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->fillForm(['hosts' => $hosts])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing($hosts, $accommodation->hosts()->pluck('hosts.id')->all());
    }

    public function test_at_the_host_choose_you_multiple_accommodations(): void
    {
        $accommodations = [$this->accommodation('Strandhuis')->id, $this->accommodation('Stadshuis')->id];

        Livewire::actingAs($this->owner())
            ->test(ManageHosts::class)
            ->callAction('create', ['name' => 'Sarit', 'properties' => $accommodations])
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing($accommodations, Host::where('name', 'Sarit')->firstOrFail()->properties()->pluck('properties.id')->all());
    }

    public function test_the_link_is_on_both_sides_same(): void
    {
        $accommodation = $this->accommodation();
        $host = Host::create(['name' => 'Sarit']);

        Livewire::actingAs($this->owner())
            ->test(ManageHosts::class)
            ->callAction(TestAction::make('edit')->table($host), ['properties' => [$accommodation->id]])
            ->assertHasNoActionErrors();

        Livewire::test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->assertSchemaStateSet(['hosts' => [$host->id]]);
    }

    public function test_every_linked_host_sees_the_accommodation_and_her_bookings(): void
    {
        $accommodation = $this->accommodation();
        $booking = $this->booking($accommodation);
        $sarit = Host::create(['name' => 'Sarit']);
        $luis = Host::create(['name' => 'Luis']);
        $accommodation->hosts()->attach([$sarit->id, $luis->id]);

        foreach ([$sarit, $luis] as $host) {
            Livewire::actingAs($this->hostUser($host))
                ->test(ListBookings::class)
                ->assertCanSeeTableRecords([$booking]);
        }
    }

    public function test_a_host_manages_already_are_accommodations_and_nothing_otherwise(): void
    {
        $first = $this->accommodation('Eerste');
        $second = $this->accommodation('Tweede');
        $foreign = $this->accommodation('Vreemd');
        $sarit = Host::create(['name' => 'Sarit']);
        $sarit->properties()->attach([$first->id, $second->id]);
        $foreign->hosts()->attach(Host::create(['name' => 'Ander']));

        Livewire::actingAs($this->hostUser($sarit))
            ->test(ListProperties::class)
            ->assertCanSeeTableRecords([$first, $second])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_a_linked_host_may_save_and_a_other_host_not(): void
    {
        $accommodation = $this->accommodation();
        $sarit = Host::create(['name' => 'Sarit']);
        $luis = Host::create(['name' => 'Luis']);
        $foreign = Host::create(['name' => 'Vreemd']);
        $accommodation->hosts()->attach([$sarit->id, $luis->id]);

        $this->actingAs($this->hostUser($luis));
        $accommodation->update(['name' => 'Aangepast door Luis']);
        $this->assertSame('Aangepast door Luis', $accommodation->fresh()->name);

        $this->actingAs($this->hostUser($foreign));
        $this->expectException(AuthorizationException::class);
        $accommodation->update(['name' => 'Gekaapt']);
    }

    public function test_a_host_sees_the_field_hosts_not_and_can_the_link_not_forge(): void
    {
        $accommodation = $this->accommodation();
        $sarit = Host::create(['name' => 'Sarit']);
        $intruder = Host::create(['name' => 'Indringer']);
        $accommodation->hosts()->attach($sarit);

        Livewire::actingAs($this->hostUser($sarit))
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->assertFormFieldHidden('hosts')
            ->set('data.hosts', [$intruder->id])
            ->call('save');

        $this->assertEquals([$sarit->id], $accommodation->hosts()->pluck('hosts.id')->all());
    }

    public function test_a_host_with_the_screen_hosts_can_itself_no_accommodation_give(): void
    {
        $foreign = $this->accommodation();
        $sarit = Host::create(['name' => 'Sarit']);

        Livewire::actingAs($this->hostUser($sarit))
            ->test(ManageHosts::class)
            ->callAction(TestAction::make('edit')->table($sarit), ['properties' => [$foreign->id]]);

        $this->assertSame(0, $foreign->hosts()->count());
    }

    public function test_all_linked_hosts_get_the_notification(): void
    {
        $accommodation = $this->accommodation();
        $sarit = Host::create(['name' => 'Sarit']);
        $luis = Host::create(['name' => 'Luis']);
        $accommodation->hosts()->attach([$sarit->id, $luis->id]);
        $users = [$this->hostUser($sarit)->id, $this->hostUser($luis)->id];
        $outsider = $this->hostUser(Host::create(['name' => 'Ander']));

        $recipients = collect(AdminNotifications::recipients(AdminNotification::NewBooking, $this->booking($accommodation)))
            ->whereInstanceOf(User::class)
            ->pluck('id');

        $this->assertEqualsCanonicalizing($users, $recipients->all());
        $this->assertNotContains($outsider->id, $recipients);
    }

    public function test_the_site_shows_all_hosts_and_their_names_at_a_reply(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->hosts()->attach([
            Host::create(['name' => 'Sarit', 'bio' => 'Woont aan het strand.'])->id,
            Host::create(['name' => 'Luis', 'bio' => 'Kent de stad.'])->id,
        ]);
        $this->reviewWithReply($accommodation);

        $this->withSession(['locale' => 'nl'])
            ->get("/{$accommodation->slug}")
            ->assertOk()
            ->assertSee('Gehost door Sarit')
            ->assertSee('Woont aan het strand.')
            ->assertSee('Gehost door Luis')
            ->assertSee('Kent de stad.')
            ->assertSee('Reactie van Sarit &amp; Luis', false);
    }

    public function test_without_host_mentions_a_reply_the_company(): void
    {
        $accommodation = $this->accommodation();
        $this->reviewWithReply($accommodation);

        $this->withSession(['locale' => 'nl'])
            ->get("/{$accommodation->slug}")
            ->assertOk()
            ->assertDontSee('Gehost door')
            ->assertSee('Reactie van '.config('app.name'));
    }
}
