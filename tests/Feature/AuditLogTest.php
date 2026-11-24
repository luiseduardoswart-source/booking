<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ActivityLog\ActivityLogResource;
use App\Filament\Resources\ActivityLog\Pages\ListActivities;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Models\Booking;
use App\Models\GuestLogin;
use App\Models\Property;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Support\GuestDataEraser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The log (Logboek): who signed in or failed to, who changed an account or
 * a setting, only for the owner. And the right to be forgotten: the guest
 * data of finished stays can be erased.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator, 'password' => 'the-right-long-password']);
    }

    private function events(string $event): \Illuminate\Support\Collection
    {
        return Activity::query()->where('event', $event)->get();
    }

    public function test_sign_ins_and_failed_sign_ins_are_logged(): void
    {
        $owner = $this->owner();

        $this->assertFalse(Auth::attempt(['email' => $owner->email, 'password' => 'a-guess']));
        $this->assertFalse(Auth::attempt(['email' => 'nobody@example.com', 'password' => 'a-guess']));
        $this->assertTrue(Auth::attempt(['email' => $owner->email, 'password' => 'the-right-long-password']));

        $failed = $this->events('login_failed');
        $this->assertCount(2, $failed);
        $this->assertSame('nobody@example.com', $failed[1]->properties['email']);
        $this->assertTrue($failed[0]->subject->is($owner));
        $this->assertCount(1, $this->events('login'));
        // Never the password that was tried.
        $this->assertStringNotContainsString('a-guess', Activity::query()->get()->toJson());
    }

    public function test_which_setting_changed_is_logged_but_never_its_value(): void
    {
        $this->actingAs($this->owner());
        Setting::set('payments', 'mollie_api_key', 'live_secret_key_value');
        Setting::set('payments', 'provider', 'mollie');
        Setting::set('payments', 'provider', 'mollie'); // unchanged: no line

        $changed = $this->events('setting_changed');
        $this->assertSame(['payments.mollie_api_key', 'payments.provider'], $changed->map(fn ($a) => $a->properties['setting'])->all());
        $this->assertStringNotContainsString('live_secret_key_value', Activity::query()->get()->toJson());

        // What the site changes itself (no one signed in) is not logged.
        auth()->logout();
        Setting::set('chat', 'month_count', '5');
        $this->assertCount(2, $this->events('setting_changed'));
    }

    public function test_changes_to_an_account_and_its_two_factor_are_logged(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $staff->update(['role' => UserRole::Administrator]);
        $staff->forceFill(['app_authentication_secret' => 'secret'])->save();
        $staff->disableTwoFactor();

        $roleChange = Activity::query()->where('subject_id', $staff->id)->where('event', 'updated')->sole();
        $this->assertSame('administrator', $roleChange->attribute_changes['attributes']['role']);
        $this->assertTrue($roleChange->causer->is($owner));
        $this->assertCount(1, $this->events('two_factor_on'));
        $this->assertCount(1, $this->events('two_factor_off'));
        $this->assertStringNotContainsString('"secret"', Activity::query()->get()->toJson());
    }

    public function test_only_the_owner_reads_the_log(): void
    {
        $owner = $this->owner();
        Auth::attempt(['email' => 'nobody@example.com', 'password' => 'x']);

        Livewire::actingAs($owner)->test(ListActivities::class)
            ->assertOk()
            ->assertSee('nobody@example.com')
            ->assertSee(__('Sign-in failed'));

        foreach ([UserRole::Staff, UserRole::Host] as $role) {
            $other = User::factory()->create(['role' => $role, 'permissions' => ['bookings' => \App\Support\Access::ACTIONS]]);
            $this->actingAs($other)->get(ActivityLogResource::getUrl())->assertForbidden();
        }
    }

    private function stay(string $email, string $arrival, string $departure, array $extra = []): Booking
    {
        static $n = 0;
        $n++;
        $property = Property::query()->first() ?? Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        return Booking::create([
            'booking_number' => "BK-GONE-{$n}", 'property_id' => $property->id, 'status' => BookingStatus::Completed, 'payment_status' => 'paid',
            'guest_name' => 'Jan de Vries', 'guest_email' => $email, 'guest_phone' => '+31 6 1234 5678', 'guest_city' => 'Utrecht',
            'notes' => 'We komen laat', 'internal_notes' => 'Vaste gast', 'form_answers' => [['label' => 'Kenteken', 'value' => 'AB-12-CD']],
            'arrival_date' => $arrival, 'departure_date' => $departure, 'guests' => 2,
            'total_cents' => 30000, 'down_payment_cents' => 30000, 'price_snapshot' => ['lines' => []], ...$extra,
        ]);
    }

    public function test_the_owner_erases_the_guest_data_of_finished_stays(): void
    {
        $owner = $this->owner();
        $past = $this->stay('jan@example.com', today()->subDays(40)->toDateString(), today()->subDays(37)->toDateString());
        $cancelled = $this->stay('Jan@Example.com', today()->addDays(20)->toDateString(), today()->addDays(23)->toDateString(), ['status' => BookingStatus::Cancelled]);
        $coming = $this->stay('jan@example.com', today()->addDays(60)->toDateString(), today()->addDays(63)->toDateString(), ['status' => BookingStatus::Confirmed]);
        $other = $this->stay('piet@example.com', today()->subDays(40)->toDateString(), today()->subDays(37)->toDateString());
        Review::create(['booking_id' => $past->id, 'property_id' => $past->property_id, 'rating' => 5, 'comment' => 'Top', 'guest_name' => 'Jan de Vries', 'status' => 'approved']);
        GuestLogin::create(['email' => 'jan@example.com', 'kind' => 'link', 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDay()]);

        Livewire::actingAs($owner)
            ->test(EditBooking::class, ['record' => $past->getKey()])
            ->callAction(TestAction::make('erase_guest'))
            ->assertNotified();

        foreach ([$past, $cancelled] as $stay) {
            $stay->refresh();
            $this->assertSame(GuestDataEraser::ANONYMOUS, $stay->guest_name);
            $this->assertNull($stay->guest_phone);
            $this->assertNull($stay->notes);
            $this->assertNull($stay->internal_notes);
            $this->assertNull($stay->form_answers);
            $this->assertSame(30000, $stay->total_cents); // the bookkeeping stays
        }
        $this->assertSame('Jan de Vries', $coming->refresh()->guest_name);
        $this->assertSame('Jan de Vries', $other->refresh()->guest_name);
        $this->assertSame(GuestDataEraser::ANONYMOUS, Review::query()->sole()->guest_name);
        $this->assertSame(0, GuestLogin::query()->count());
        // No log line keeps the old name.
        $this->assertStringNotContainsString('jan@example.com', strtolower(Activity::query()->where('subject_id', $past->id)->get()->toJson()));
        $this->assertCount(2, $this->events('guest_data_erased'));
    }

    public function test_only_the_owner_can_erase_guest_data(): void
    {
        $past = $this->stay('jan@example.com', today()->subDays(40)->toDateString(), today()->subDays(37)->toDateString());
        $staff = User::factory()->create(['role' => UserRole::Staff, 'permissions' => ['bookings' => \App\Support\Access::ACTIONS]]);

        Livewire::actingAs($staff)
            ->test(EditBooking::class, ['record' => $past->getKey()])
            ->assertActionHidden(TestAction::make('erase_guest'));

        $this->assertSame('Jan de Vries', DB::table('bookings')->where('id', $past->id)->value('guest_name'));
    }
}
