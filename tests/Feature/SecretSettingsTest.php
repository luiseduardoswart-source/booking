<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Passwords and API keys in the settings are stored encrypted and never
 * shown again: not in the database, not in the settings cache, not in the
 * browser. The rest of the site reads them as before.
 */
class SecretSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk_live_supersecret123';

    private function stored(string $group, string $key): ?string
    {
        return DB::table('settings')->where('group', $group)->where('key', $key)->value('value');
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_a_key_is_stored_encrypted_and_read_as_before(): void
    {
        Setting::set('payments', 'stripe_secret_key', self::KEY);
        Setting::set('company', 'name', 'Beach BV');

        $this->assertNotSame(self::KEY, $this->stored('payments', 'stripe_secret_key'));
        $this->assertStringNotContainsString('supersecret', (string) $this->stored('payments', 'stripe_secret_key'));
        $this->assertSame(self::KEY, Setting::get('payments', 'stripe_secret_key'));

        // Ordinary settings stay as they are.
        $this->assertSame('Beach BV', $this->stored('company', 'name'));
    }

    public function test_the_settings_page_never_sends_a_key_to_the_browser(): void
    {
        Setting::set('payments', 'stripe_secret_key', self::KEY);
        Setting::set('mail', 'password', 'mailbox-password');

        $page = Livewire::actingAs($this->owner())->test(Settings::class)
            ->assertSet('data.payments_stripe_secret_key', null)
            ->assertSet('data.mail_password', null);

        $this->assertStringNotContainsString('supersecret', $page->html());
        $this->assertStringNotContainsString('mailbox-password', $page->html());
        $this->assertStringNotContainsString('supersecret', json_encode($page->snapshot));
    }

    public function test_empty_keeps_the_key_a_new_one_replaces_it_and_the_button_removes_it(): void
    {
        Setting::set('payments', 'stripe_secret_key', self::KEY);
        $owner = $this->owner();

        Livewire::actingAs($owner)->test(Settings::class)
            ->fillForm(['company_name' => 'Beach BV'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(self::KEY, Setting::get('payments', 'stripe_secret_key'));

        Livewire::actingAs($owner)->test(Settings::class)
            ->fillForm(['company_name' => 'Beach BV', 'payments_stripe_secret_key' => 'sk_live_new'])
            ->call('save');
        $this->assertSame('sk_live_new', Setting::get('payments', 'stripe_secret_key'));

        Livewire::actingAs($owner)->test(Settings::class)
            ->callAction(TestAction::make('forget_payments_stripe_secret_key')->schemaComponent('payments_stripe_secret_key', 'form'));
        $this->assertNull(Setting::get('payments', 'stripe_secret_key'));
    }

    public function test_keys_stored_before_this_update_are_encrypted_by_the_migration(): void
    {
        DB::table('settings')->insert(['group' => 'ai', 'key' => 'claude_api_key', 'value' => 'sk-ant-plain', 'created_at' => now(), 'updated_at' => now()]);
        Setting::forgets();

        // Until the migration has run, the plain value still works.
        $this->assertSame('sk-ant-plain', Setting::get('ai', 'claude_api_key'));

        (require database_path('migrations/2026_11_20_100000_encrypt_secret_settings.php'))->up();

        $this->assertNotSame('sk-ant-plain', $this->stored('ai', 'claude_api_key'));
        $this->assertSame('sk-ant-plain', Setting::get('ai', 'claude_api_key'));
    }

    public function test_a_key_encrypted_with_another_app_key_counts_as_not_set(): void
    {
        $other = new Encrypter(random_bytes(32), 'AES-256-CBC');
        DB::table('settings')->insert(['group' => 'mail', 'key' => 'password', 'value' => $other->encryptString('x'), 'created_at' => now(), 'updated_at' => now()]);
        Setting::forgets();

        $this->assertNull(Setting::get('mail', 'password'));
    }
}
