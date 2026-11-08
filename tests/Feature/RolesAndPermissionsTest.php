<?php

namespace Tests\Feature;

use App\Enums\CalendarBlockType;
use App\Enums\UserRole;
use App\Filament\Auth\Profile;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Booking;
use App\Models\CalendarBlock;
use App\Models\Host;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Notifications\AdminInvitation;
use App\Support\Access;
use App\Support\Invitation;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Roles and permissions: the owner may do everything, a staff member and a host only
 * what the owner ticks, and a host sees and touches only their own
 * accommodations.
 */
class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Property $accommodationA;

    private Property $accommodationB;

    private Booking $bookingA;

    private Booking $bookingB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        [$this->accommodationA, $this->bookingA] = $this->accommodationWithBooking('A');
        [$this->accommodationB, $this->bookingB] = $this->accommodationWithBooking('B');
    }

    /** @return array{0: Property, 1: Booking} */
    private function accommodationWithBooking(string $letter): array
    {
        $host = Host::create(['name' => "Host {$letter}"]);
        $accommodation = Property::create([
            'name' => "Woning {$letter}", 'slug' => 'accommodation-'.strtolower($letter), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
        $accommodation->hosts()->attach($host);
        $booking = Booking::create([
            'booking_number' => "BK-{$letter}", 'property_id' => $accommodation->id,
            'status' => 'confirmed', 'payment_status' => 'unpaid',
            'guest_name' => "Gast {$letter}", 'guest_email' => strtolower($letter).'@example.com',
            'arrival_date' => '2026-12-01', 'departure_date' => '2026-12-03', 'guests' => 2,
            'total_cents' => 20000, 'down_payment_cents' => 6000, 'price_snapshot' => ['lines' => []],
        ]);

        return [$accommodation, $booking];
    }

    /** @param  array<string, array<int, string>>|null  $permissions */
    private function user(UserRole $role, ?array $permissions = null, ?Host $host = null): User
    {
        return User::factory()->create(['role' => $role, 'permissions' => $permissions, 'host_id' => $host?->id]);
    }

    private function hostA(array $permissions = ['bookings' => ['view', 'edit']]): User
    {
        return $this->user(UserRole::Host, $permissions, $this->accommodationA->hosts->first());
    }

    // --- Who may do what ----------------------------------------------------------

    public function test_owner_may_everything_and_guest_nothing(): void
    {
        $this->assertTrue(Access::may($this->user(UserRole::Administrator), 'bookings', Access::DELETE));
        $this->assertFalse(Access::may($this->user(UserRole::Guest, Access::everything()), 'bookings', Access::VIEW));
        $this->assertFalse(Access::may(null, 'bookings', Access::VIEW));
    }

    public function test_staff_without_configured_permissions_keeps_everything_what_he_had(): void
    {
        $staff = $this->user(UserRole::Staff);

        $this->assertTrue($staff->may('bookings', Access::DELETE));
        $this->actingAs($staff)->get('/admin/bookings')->assertOk();
        $this->actingAs($staff)->get('/admin/settings')->assertForbidden();
        $this->actingAs($staff)->get('/admin/users')->assertForbidden();
    }

    public function test_only_checked_actions_may(): void
    {
        $staff = $this->user(UserRole::Staff, ['bookings' => ['view']]);

        $this->assertTrue($staff->may('bookings', Access::VIEW));
        $this->assertFalse($staff->may('bookings', Access::EDIT));
        $this->assertFalse($staff->may('accommodations', Access::VIEW));

        $this->actingAs($staff)->get('/admin/bookings')->assertOk();
        $this->actingAs($staff)->get("/admin/bookings/{$this->bookingA->id}/edit")->assertForbidden();
        $this->actingAs($staff)->get('/admin/properties')->assertForbidden();
    }

    public function test_a_host_without_permissions_sees_nothing(): void
    {
        $host = $this->user(UserRole::Host, null, $this->accommodationA->hosts->first());

        $this->assertTrue($host->canAccessPanel(filament()->getPanel('admin')));
        $this->actingAs($host)->get('/admin/bookings')->assertForbidden();
    }

    // --- A host sees only their own ---------------------------------------

    public function test_a_host_sees_only_bookings_of_are_own_accommodations(): void
    {
        Livewire::actingAs($this->hostA())
            ->test(ListBookings::class)
            ->assertCanSeeTableRecords([$this->bookingA])
            ->assertCanNotSeeTableRecords([$this->bookingB]);
    }

    public function test_a_host_can_a_booking_of_a_other_not_open(): void
    {
        $host = $this->hostA();

        $this->actingAs($host)->get("/admin/bookings/{$this->bookingA->id}/edit")->assertOk();
        $this->actingAs($host)->get("/admin/bookings/{$this->bookingB->id}/edit")->assertNotFound();
    }

    public function test_a_host_without_linked_profile_sees_no_single_booking(): void
    {
        $host = $this->user(UserRole::Host, ['bookings' => ['view']]);

        $this->assertSame(0, Access::restrict(Booking::query(), $host)->count());
        $this->assertSame(0, Access::restrict(Property::query(), $host)->count());
    }

    public function test_restrict_follows_also_payments_via_the_booking(): void
    {
        Payment::create(['booking_id' => $this->bookingA->id, 'type' => 'down_payment', 'status' => 'open', 'amount_cents' => 6000, 'provider' => 'fake']);
        Payment::create(['booking_id' => $this->bookingB->id, 'type' => 'down_payment', 'status' => 'open', 'amount_cents' => 6000, 'provider' => 'fake']);

        $custom = Access::restrict(Payment::query(), $this->hostA())->pluck('booking_id')->all();

        $this->assertSame([$this->bookingA->id], $custom);
        $this->assertSame(2, Access::restrict(Payment::query(), $this->user(UserRole::Staff))->count());
    }

    public function test_choice_lists_show_a_host_only_are_own_accommodations(): void
    {
        $this->actingAs($this->hostA());

        $this->assertSame([$this->accommodationA->id], Access::onlyOwn(Property::query())->pluck('id')->all());
    }

    // --- A forged request does not get through either --------------------------

    public function test_a_host_can_nothing_on_the_accommodation_of_a_other_set(): void
    {
        $this->actingAs($this->hostA(['calendar' => Access::ACTIONS]));

        CalendarBlock::create(['property_id' => $this->accommodationA->id, 'start_date' => '2026-12-10', 'end_date' => '2026-12-11', 'type' => CalendarBlockType::cases()[0]]);

        $this->expectException(AuthorizationException::class);
        CalendarBlock::create(['property_id' => $this->accommodationB->id, 'start_date' => '2026-12-10', 'end_date' => '2026-12-11', 'type' => CalendarBlockType::cases()[0]]);
    }

    public function test_a_host_can_a_booking_not_to_a_other_move(): void
    {
        $this->actingAs($this->hostA());

        $this->expectException(AuthorizationException::class);
        $this->bookingA->update(['property_id' => $this->accommodationB->id]);
    }

    public function test_a_host_can_a_booking_of_a_other_not_change_or_delete(): void
    {
        $this->actingAs($this->hostA());

        try {
            $this->bookingB->update(['guest_name' => 'Gekaapt']);
            $this->fail('Wijzigen had geweigerd moeten worden.');
        } catch (AuthorizationException) {
        }

        $this->expectException(AuthorizationException::class);
        $this->bookingB->delete();
    }

    public function test_a_new_accommodation_of_a_host_gets_are_profile(): void
    {
        $this->actingAs($this->hostA(['accommodations' => Access::ACTIONS]));

        $new = Property::create(['name' => 'Nieuw', 'slug' => 'new', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true, 'base_price_cents' => 5000]);

        $this->assertEquals($this->accommodationA->hosts->modelKeys(), $new->hosts->modelKeys());
    }

    public function test_without_logged_in_host_changes_there_nothing_on_the_guarding(): void
    {
        // The website and cron jobs work without a logged-in host.
        $this->bookingB->update(['guest_name' => 'Aangepast door de site']);

        $this->assertSame('Aangepast door de site', $this->bookingB->fresh()->guest_name);
    }

    // --- Platform screens ------------------------------------------------------

    public function test_defaults_is_a_own_permission(): void
    {
        $onlyDefaults = $this->user(UserRole::Staff, ['defaults' => ['edit']]);

        $this->actingAs($onlyDefaults)->get('/admin/properties/defaults')->assertOk();
        $this->actingAs($onlyDefaults)->get('/admin/properties')->assertForbidden();
        $this->actingAs($this->hostA())->get('/admin/properties/defaults')->assertForbidden();
    }

    public function test_media_files_only_with_the_permission_media_admin(): void
    {
        $this->actingAs($this->hostA(['pages' => ['edit']]))->getJson('/admin-media/list')->assertForbidden();
        $this->actingAs($this->hostA(['media' => ['edit']]))->getJson('/admin-media/list')->assertOk();
    }

    public function test_staff_without_checkmarks_keeps_media_admin_closed_as_before(): void
    {
        $staff = $this->user(UserRole::Staff);

        $this->assertFalse($staff->may('media', Access::EDIT));
        $this->assertSame([], Access::defaultForStaff()['media']);
        $this->assertSame(Access::ACTIONS, Access::defaultForStaff()['bookings']);
    }

    // --- Own host profile -----------------------------------------------------

    public function test_a_host_with_the_permission_adjusts_are_own_profile_on(): void
    {
        $host = $this->hostA(['host_profile' => ['edit']]);

        $this->actingAs($host)->get('/admin/profile')->assertOk()->assertSee('Host A');

        Livewire::actingAs($host)
            ->test(Profile::class)
            ->fillForm(['host' => ['name' => 'Host A', 'bio' => 'Welkom in Cartagena!']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Welkom in Cartagena!', $this->accommodationA->hosts->first()->fresh()->bio);
        $this->assertNull($this->accommodationB->hosts->first()->fresh()->bio);
    }

    public function test_a_host_without_the_permission_can_are_profile_not_adjust(): void
    {
        $host = $this->hostA(['bookings' => ['view']]);

        Livewire::actingAs($host)
            ->test(Profile::class)
            ->fillForm(['host' => ['name' => 'Gekaapt', 'bio' => 'Mag niet']])
            ->call('save');

        $this->assertSame('Host A', $this->accommodationA->hosts->first()->fresh()->name);
        $this->assertNull($this->accommodationA->hosts->first()->fresh()->bio);
    }

    public function test_the_profile_permission_applies_only_for_a_host(): void
    {
        $this->assertFalse(Access::mayOwnHostProfile($this->user(UserRole::Staff)));
        $this->assertFalse(Access::mayOwnHostProfile($this->user(UserRole::Host, ['host_profile' => ['edit']])));
        $this->assertTrue(Access::mayOwnHostProfile($this->hostA(['host_profile' => ['edit']])));
    }

    // --- User management and invitation -------------------------------------

    public function test_users_is_only_for_the_owner(): void
    {
        $this->actingAs($this->hostA())->get('/admin/users')->assertForbidden();
        $this->actingAs($this->user(UserRole::Administrator))->get('/admin/users')->assertOk();
    }

    public function test_owner_makes_a_host_on_and_that_gets_a_invitation(): void
    {
        Notification::fake();

        Livewire::actingAs($this->user(UserRole::Administrator))
            ->test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'Nieuwe host',
                'email' => 'host@example.com',
                'role' => UserRole::Host->value,
                'host_id' => $this->accommodationA->hosts->first()->id,
                'permissions' => ['bookings' => ['view']],
            ])
            ->assertHasNoActionErrors();

        $host = User::where('email', 'host@example.com')->firstOrFail();

        $this->assertSame(UserRole::Host, $host->role);
        $this->assertNull($host->password_set_at);
        $this->assertSame(['view'], $host->permissions['bookings']);
        $this->assertSame([], $host->permissions['accommodations']);
        Notification::assertSentTo($host, AdminInvitation::class);
    }

    public function test_owner_makes_the_host_on_from_the_user_form_without_something_lose_to_lose(): void
    {
        Notification::fake();

        Livewire::actingAs($this->user(UserRole::Administrator))
            ->test(ManageUsers::class)
            ->mountAction('create')
            ->fillForm(['name' => 'Sarit', 'email' => 'sarit@example.com', 'role' => UserRole::Host->value])
            ->callAction(
                TestAction::make('createOption')->schemaComponent('host_id', schema: 'mountedActionSchema0'),
                ['name' => 'Host C', 'bio' => 'Over host C'],
            )
            ->assertHasNoActionErrors()
            ->assertSet('mountedActions.0.data.name', 'Sarit')
            ->assertSet('mountedActions.0.data.email', 'sarit@example.com')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $host = Host::where('name', 'Host C')->firstOrFail();

        $this->assertSame('Over host C', $host->bio);
        $this->assertSame($host->id, User::where('email', 'sarit@example.com')->firstOrFail()->host_id);
    }

    public function test_a_host_user_without_host_profile_is_not_saved(): void
    {
        Livewire::actingAs($this->user(UserRole::Administrator))
            ->test(ManageUsers::class)
            ->callAction('create', ['name' => 'Sarit', 'email' => 'sarit@example.com', 'role' => UserRole::Host->value])
            ->assertHasActionErrors(['host_id' => 'required']);

        $this->assertDatabaseMissing('users', ['email' => 'sarit@example.com']);
    }

    public function test_with_a_button_are_all_permissions_of_a_host_on(): void
    {
        Notification::fake();

        $this->userWithAllPermissions(UserRole::Host, ['host_id' => $this->accommodationA->hosts->first()->id]);

        $this->assertSame(Access::everything(), User::where('email', 'everything@example.com')->firstOrFail()->permissions);
    }

    public function test_the_button_gives_a_staff_no_permission_that_only_for_a_host_is(): void
    {
        Notification::fake();

        $this->userWithAllPermissions(UserRole::Staff);

        $this->assertSame(
            ['host_profile' => []] + Access::everything(),
            User::where('email', 'everything@example.com')->firstOrFail()->permissions,
        );
    }

    public function test_with_a_button_go_all_permissions_again_off(): void
    {
        Notification::fake();

        Livewire::actingAs($this->user(UserRole::Administrator))
            ->test(ManageUsers::class)
            ->mountAction('create')
            ->fillForm(['name' => 'Alles', 'email' => 'everything@example.com', 'role' => UserRole::Staff->value])
            ->callAction(TestAction::make('all_permissions')->schemaComponent('permissions_section', schema: 'mountedActionSchema0'))
            ->callAction(TestAction::make('no_permissions')->schemaComponent('permissions_section', schema: 'mountedActionSchema0'))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(Access::clean([]), User::where('email', 'everything@example.com')->firstOrFail()->permissions);
    }

    /** @param  array<string, mixed>  $fields */
    private function userWithAllPermissions(UserRole $role, array $fields = []): void
    {
        Livewire::actingAs($this->user(UserRole::Administrator))
            ->test(ManageUsers::class)
            ->mountAction('create')
            ->fillForm(['name' => 'Alles', 'email' => 'everything@example.com', 'role' => $role->value, ...$fields])
            ->callAction(TestAction::make('all_permissions')->schemaComponent('permissions_section', schema: 'mountedActionSchema0'))
            ->assertSet('mountedActions.0.data.name', 'Alles')
            ->callMountedAction()
            ->assertHasNoActionErrors();
    }

    public function test_only_existing_screens_and_actions_are_saved(): void
    {
        $clean = Access::clean([
            'bookings' => ['delete', 'self_made_up', 'view'],
            'payments' => ['create'], // does not exist for payments
            'exists_not' => ['view'],
        ]);

        $this->assertSame(['view', 'delete'], $clean['bookings']);
        $this->assertSame([], $clean['payments']);
        $this->assertArrayNotHasKey('exists_not', $clean);
    }

    public function test_the_owner_can_itself_not_delete(): void
    {
        $owner = $this->user(UserRole::Administrator);
        $other = $this->user(UserRole::Administrator);

        Livewire::actingAs($owner)
            ->test(ManageUsers::class)
            ->assertActionHidden(TestAction::make('delete')->table($owner))
            ->assertActionVisible(TestAction::make('delete')->table($other));
    }

    public function test_with_the_invitation_chooses_the_user_itself_a_password_and_the_link_works_a_time(): void
    {
        $host = $this->hostA();
        $link = Invitation::link($host);
        $password = 'a-own-long-password-2026';

        $this->get($link)->assertOk()->assertSee($host->email);

        $this->post($link, ['password' => $password, 'password_confirmation' => $password])
            ->assertOk()
            ->assertSee(__('Your password has been set. You can now log in.'));

        $host->refresh();
        $this->assertTrue(Hash::check($password, $host->password));
        $this->assertNotNull($host->password_set_at);
        $this->assertGuest(); // logging in goes through the normal screen, with 2FA

        // The same link again: used.
        $this->get($link)->assertSee(__('This link has expired or has already been used. Ask the owner to send you a new invitation.'));
    }

    public function test_a_forged_or_expired_invitation_works_not(): void
    {
        $host = $this->hostA();
        $form = 'name="password"';
        $before = $host->password;

        $this->get(Invitation::link($host))->assertSee($form, false); // for comparison: valid
        $this->get(str_replace('check=', 'check=x', Invitation::link($host)))->assertDontSee($form, false);
        $this->get("/invitation/{$host->id}")->assertDontSee($form, false);

        $link = Invitation::link($host);
        $this->travel(Invitation::VALID_DAYS + 1)->days();
        $this->post($link, ['password' => 'a-own-long-password-2026', 'password_confirmation' => 'a-own-long-password-2026'])
            ->assertDontSee($form, false);

        $this->assertSame($before, $host->fresh()->password);
    }
}
