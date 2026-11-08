<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Enums\AdminNotification;
use App\Enums\UserRole;
use App\Filament\Auth\Profile;
use App\Mail\AdminNotificationMail;
use App\Models\Host;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AdminInvitation;
use App\Support\Invitation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every user has their own language: they get their mails in it and the admin
 * opens in it. The owner in the Netherlands gets Dutch, a host in
 * Colombia gets Spanish.
 */
class UsersLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function host(string $locale): User
    {
        $host = User::factory()->create([
            'role' => UserRole::Host,
            'locale' => $locale,
            'host_id' => Host::create(['name' => 'Host in Colombia'])->id,
            'permissions' => ['bookings' => ['view']],
        ]);
        $host->forceFill(['password_set_at' => now()])->save();

        return $host;
    }

    public function test_notifications_go_in_the_language_of_every_recipient(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['role' => UserRole::Administrator, 'email' => 'owner@example.com', 'locale' => 'de']);
        Setting::set('company', 'email', 'Owner@Example.com');
        $host = $this->host('es');

        $accommodation = Property::create([
            'name' => 'Casa', 'slug' => 'casa', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000,
        ]);
        $accommodation->hosts()->attach($host->host_id);
        app(BookingEngine::class)->create($accommodation, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-04'), 2, ['name' => 'Jan', 'email' => 'jan@example.com']);

        $languages = Mail::sent(AdminNotificationMail::class, fn ($mail) => $mail->notification === AdminNotification::NewBooking)
            ->mapWithKeys(fn (AdminNotificationMail $mail) => [$mail->to[0]['address'] => $mail->locale])
            ->all();

        $this->assertCount(2, $languages);
        $this->assertSame('es', $languages[$host->email]);
        $this->assertSame('de', $languages[$owner->email]);

        $spanish = Mail::sent(AdminNotificationMail::class, fn ($mail) => $mail->hasTo($host->email))->first();
        $spanish->assertSeeInHtml(trans('Open booking', [], 'es'));
    }

    public function test_the_invitation_goes_in_the_language_of_the_new_user(): void
    {
        Notification::fake();
        $host = $this->host('es');

        Invitation::send($host);

        Notification::assertSentTo($host, AdminInvitation::class, fn ($notification, $channels, $recipient, $language) => $language === 'es');
    }

    public function test_the_admin_opens_in_the_language_of_the_account(): void
    {
        $this->actingAs($this->host('es'))->get('/admin')->assertOk()->assertSee('lang="es"', false);
    }

    public function test_the_user_adjusts_are_language_itself_on_at_are_profile(): void
    {
        $host = $this->host('es');

        Livewire::actingAs($host)->test(Profile::class)
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('en', $host->fresh()->locale);

        Livewire::actingAs($host)->test(Profile::class)
            ->fillForm(['locale' => 'xx'])
            ->call('save')
            ->assertHasFormErrors(['locale']);
        $this->assertSame('en', $host->fresh()->locale);
    }

    public function test_the_language_switch_in_the_admin_saves_the_language_at_the_account_that_on_the_site_not(): void
    {
        $host = $this->host('es');

        $this->actingAs($host)->get('/language/en')->assertRedirect();
        $this->assertSame('es', $host->fresh()->locale); // website: this session only

        $this->actingAs($host)->get(route('filament.admin.language', 'en'))->assertRedirect();
        $this->assertSame('en', $host->fresh()->locale);
    }

    public function test_a_guest_can_via_the_language_switch_no_account_adjust(): void
    {
        $guest = User::factory()->create(['role' => UserRole::Guest, 'locale' => 'nl']);

        $this->actingAs($guest)->get(route('filament.admin.language', 'en'))->assertForbidden();
        $this->assertSame('nl', $guest->fresh()->locale);
    }
}
