<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Filament\Auth\Pages\EditProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Changing your own password and two-step verification (authenticator app),
 * voluntary until the owner makes it mandatory under Settings.
 */
class TwoStepAndProfileTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const PASSWORD = 'old-password-or-de-owner';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator, 'password' => self::PASSWORD]);
    }

    public function test_the_profile_page_is_reachable(): void
    {
        $this->actingAs($this->owner())->get('/admin/profile')->assertOk();
    }

    public function test_password_change_first_asks_for_the_current_password(): void
    {
        $owner = $this->owner();
        $new = 'a-new-long-password-2026';

        Livewire::actingAs($owner)
            ->test(EditProfile::class)
            ->fillForm(['password' => $new, 'passwordConfirmation' => $new, 'currentPassword' => 'this-is-not-my-password'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check(self::PASSWORD, $owner->fresh()->password));

        Livewire::actingAs($owner)
            ->test(EditProfile::class)
            ->fillForm(['password' => $new, 'passwordConfirmation' => $new, 'currentPassword' => self::PASSWORD])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check($new, $owner->fresh()->password));
    }

    public function test_a_short_password_is_rejected(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditProfile::class)
            ->fillForm(['password' => 'kort1234', 'passwordConfirmation' => 'kort1234', 'currentPassword' => self::PASSWORD])
            ->call('save')
            ->assertHasFormErrors(['password']);
    }

    public function test_without_obligation_you_can_continue_without_2fa(): void
    {
        $this->actingAs($this->owner())->get('/admin')->assertOk();
    }

    public function test_required_sends_users_without_2fa_to_set_it_up(): void
    {
        Setting::set('security', 'two_step_required', '1');

        $this->actingAs($this->owner())
            ->get('/admin')
            ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
    }

    public function test_required_lets_users_with_2fa_through(): void
    {
        Setting::set('security', 'two_step_required', '1');
        $owner = $this->owner();
        $owner->saveAppAuthenticationSecret(self::SECRET);

        $this->actingAs($owner)->get('/admin')->assertOk();
    }

    public function test_the_switch_in_settings_works_immediately(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test(Settings::class)
            ->fillForm(['company_name' => 'Strandhuis BV', 'security_two_step_required' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1', Setting::get('security', 'two_step_required'));
        $this->actingAs($owner)->get('/admin')->assertRedirect();
    }

    public function test_the_secret_is_encrypted_in_the_database(): void
    {
        $owner = $this->owner();
        $owner->saveAppAuthenticationSecret(self::SECRET);
        $owner->saveAppAuthenticationRecoveryCodes(['code-a', 'code-two']);

        $row = DB::table('users')->where('id', $owner->id)->first();

        $this->assertStringNotContainsString(self::SECRET, $row->app_authentication_secret);
        $this->assertStringNotContainsString('code-a', $row->app_authentication_recovery_codes);
        $this->assertSame(self::SECRET, $owner->fresh()->getAppAuthenticationSecret());
        $this->assertArrayNotHasKey('app_authentication_secret', $owner->fresh()->toArray());
    }

    public function test_emergency_command_switches_2fa_off_for_an_account(): void
    {
        $owner = $this->owner();
        $owner->saveAppAuthenticationSecret(self::SECRET);
        $owner->saveAppAuthenticationRecoveryCodes(['code-a']);

        $this->artisan('admin:disable-two-factor', ['email' => $owner->email])->assertSuccessful();

        $owner->refresh();
        $this->assertFalse($owner->hasTwoFactor());
        $this->assertNull($owner->getAppAuthenticationRecoveryCodes());

        $this->artisan('admin:disable-two-factor', ['email' => 'unknown@example.com'])->assertFailed();
    }
}
