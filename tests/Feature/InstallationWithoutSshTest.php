<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Filament\Widgets\FinishInstallation;
use App\Http\Controllers\InstallController;
use App\Models\Setting;
use App\Models\User;
use App\Support\FirstStart;
use App\Support\ScheduledTasks;
use App\Support\DirectoryLayout;
use App\Support\MailSettings;
use App\Support\Access;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Installing without SSH: upload the zip package, unpack it, open /install.
 * After that the admin sets up the mailbox and the dashboard points out the cron job.
 */
class InstallationWithoutSshTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->directory = storage_path('framework/testing/first-start-'.uniqid());
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function administrator(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function mailbox(): void
    {
        foreach ([
            'sender' => 'bookings@example.nl', 'host' => 'smtp.example.nl', 'security' => 'ssl',
            'user' => 'bookings@example.nl', 'password' => 'mailbox-password',
        ] as $key => $value) {
            Setting::set('mail', $key, $value);
        }
    }

    /** A new mail manager, as on the next request. */
    private function fresh(): void
    {
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');
        $this->app->make('mail.manager');
    }

    public function test_the_first_start_makes_a_env_with_key_and_without_error_details(): void
    {
        File::put($this->directory.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nDB_CONNECTION=sqlite\n");

        FirstStart::ensureEnv($this->directory);

        $env = File::get($this->directory.'/.env');
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $env);
        $this->assertStringContainsString("APP_DEBUG=false\n", $env);
        // Production only after the wizard: otherwise CanonicalHost sends everyone to
        // the APP_URL from the example (localhost).
        $this->assertStringContainsString("APP_ENV=local\n", $env);
        $this->assertStringContainsString("DB_CONNECTION=sqlite\n", $env);
    }

    public function test_a_existing_env_stays_untouched(): void
    {
        File::put($this->directory.'/.env.example', "APP_KEY=\n");
        File::put($this->directory.'/.env', "APP_KEY=base64:custom\nAPP_DEBUG=true\n");

        FirstStart::ensureEnv($this->directory);

        $this->assertSame("APP_KEY=base64:custom\nAPP_DEBUG=true\n", File::get($this->directory.'/.env'));
    }

    public function test_without_example_is_there_nothing_made(): void
    {
        FirstStart::ensureEnv($this->directory);

        $this->assertFileDoesNotExist($this->directory.'/.env');
    }

    /**
     * A browser in a language the site does not offer falls back to APP_LOCALE.
     * The source language is English, so a new installation falls back to English.
     */
    public function test_a_new_installation_falls_back_to_english(): void
    {
        $example = File::get(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_LOCALE=en$/m', $example);
        $this->assertMatchesRegularExpression('/^APP_FALLBACK_LOCALE=en$/m', $example);
    }

    public function test_the_app_map_rejects_every_visitor_as_he_after_all_in_the_web_dir_is(): void
    {
        $rules = array_values(array_filter(
            array_map('trim', file(base_path('.htaccess'))),
            fn (string $rule) => $rule !== '' && ! str_starts_with($rule, '#'),
        ));

        $this->assertSame(['Require all denied'], $rules);
    }

    public function test_at_the_regular_layout_stays_public_the_web_dir(): void
    {
        File::ensureDirectoryExists($this->directory.'/booking/public');
        File::ensureDirectoryExists($this->directory.'/public_html');

        $this->assertNull(DirectoryLayout::publicDir($this->directory.'/booking'));
        // And this installation itself (local, or via SSH with a symlink).
        $this->assertSame(base_path('public'), public_path());
    }

    public function test_at_the_zip_package_is_public_html_next_to_the_app_the_web_dir(): void
    {
        File::ensureDirectoryExists($this->directory.'/booking/app');
        File::ensureDirectoryExists($this->directory.'/public_html');

        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'public_html', DirectoryLayout::publicDir($this->directory.'/booking'));
    }

    public function test_without_public_and_without_public_html_changes_there_nothing(): void
    {
        File::ensureDirectoryExists($this->directory.'/booking/app');

        $this->assertNull(DirectoryLayout::publicDir($this->directory.'/booking'));
    }

    public function test_index_php_finds_the_app_also_from_public_html(): void
    {
        $index = File::get(public_path('index.php'));

        $this->assertStringContainsString("is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/..' : __DIR__.'/../".DirectoryLayout::APP_DIR."'", $index);
        $this->assertStringNotContainsString("__DIR__.'/../vendor/autoload.php';", $index);
    }

    public function test_the_zip_package_lets_tests_and_development_files_gone_but_not_the_website(): void
    {
        $exportIgnore = fn (string $path): bool => str_ends_with(trim((string) shell_exec('git -C '.escapeshellarg(base_path()).' check-attr export-ignore -- '.escapeshellarg($path))), ': set');

        foreach (['tests', 'docker', 'compose.yaml', 'phpunit.xml', 'phpunit.mysql.xml'] as $path) {
            $this->assertTrue($exportIgnore($path), "$path does not belong in the zip package");
        }

        foreach (['app', 'public', '.htaccess', '.env.example', 'artisan', 'storage'] as $path) {
            $this->assertFalse($exportIgnore($path), "$path belongs in the zip package");
        }

        // Internal notes are not in the repository at all, so not in the zip either.
        foreach (['BUILD-PLAN.md', 'DEPLOYMENT.md'] as $path) {
            $ignored = trim((string) shell_exec('git -C '.escapeshellarg(base_path()).' check-ignore -- '.escapeshellarg($path)));
            $this->assertSame($path, $ignored, "$path is internal and must stay out of git");
        }

        $workflow = File::get(base_path('.github/workflows/release.yml'));
        $this->assertStringContainsString('composer install --no-dev', $workflow);
        $this->assertStringContainsString('cp -r vendor package/'.DirectoryLayout::APP_DIR.'/', $workflow);
        $this->assertStringContainsString('mv package/'.DirectoryLayout::APP_DIR.'/public package/'.DirectoryLayout::WEB_DIR, $workflow);
    }

    public function test_the_wizard_asks_the_php_version_that_the_packages_needed_have(): void
    {
        $requirements = (fn () => $this->requirements())->call(new InstallController);

        $this->assertStringStartsWith(__('PHP 8.4 or higher'), $requirements[0]['label']);
        $this->assertSame(version_compare(PHP_VERSION, '8.4.1', '>='), $requirements[0]['ok']);
    }

    public function test_without_mailbox_in_the_admin_applies_the_env(): void
    {
        $this->fresh();

        $this->assertSame('array', config('mail.default'));
        $this->assertFalse(MailSettings::sends());
    }

    public function test_the_mailbox_off_the_admin_is_used(): void
    {
        $this->mailbox();
        Setting::set('company', 'name', 'Kustverhuur');

        $this->fresh();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('smtp.example.nl', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('bookings@example.nl', config('mail.mailers.smtp.username'));
        $this->assertSame('mailbox-password', config('mail.mailers.smtp.password'));
        $this->assertSame('bookings@example.nl', config('mail.from.address'));
        $this->assertSame('Kustverhuur', config('mail.from.name'));
        $this->assertTrue(MailSettings::sends());

        Setting::set('mail', 'security', 'tls');
        $this->fresh();
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
    }

    public function test_the_mailbox_is_at_the_settings(): void
    {
        Livewire::actingAs($this->administrator())
            ->test(Settings::class)
            ->assertSet('data.mail_security', 'ssl')
            ->fillForm(['company_name' => 'Kustverhuur', 'mail_host' => 'smtp.example.nl'])
            ->call('save')
            ->assertHasFormErrors(['mail_sender' => 'required_with', 'mail_user' => 'required_with', 'mail_password' => 'required'])
            ->fillForm([
                'mail_sender' => 'bookings@example.nl', 'mail_user' => 'bookings@example.nl',
                'mail_password' => 'mailbox-password', 'mail_port' => '2465',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('smtp.example.nl', Setting::get('mail', 'host'));
        $this->assertSame('2465', Setting::get('mail', 'port'));
        $this->assertSame('mailbox-password', Setting::get('mail', 'password'));
    }

    public function test_the_test_mail_goes_to_the_administrator(): void
    {
        $administrator = $this->administrator();

        Livewire::actingAs($administrator)
            ->test(Settings::class)
            ->fillForm(['company_name' => 'Kustverhuur'])
            ->callAction(TestAction::make('test_mail')->schemaComponent('mailbox', 'form'));

        // Without a mailbox it goes to the tests' array mailer.
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame($administrator->email, $messages->first()->getEnvelope()->getRecipients()[0]->getAddress());
        Notification::assertNotified(__('No mailbox has been set up yet: the email was only written to the log file.'));
    }

    public function test_the_scheduler_ticks_every_minute_a_heartbeat_on(): void
    {
        $this->assertFalse(ScheduledTasks::run());

        $heartbeat = collect(app(Schedule::class)->events())->firstWhere('description', 'heartbeat');
        $this->assertNotNull($heartbeat);
        $this->assertSame('* * * * *', $heartbeat->expression);

        $heartbeat->run($this->app);
        $this->assertTrue(ScheduledTasks::run());

        $this->travel(11)->minutes();
        $this->assertFalse(ScheduledTasks::run());
    }

    public function test_the_dashboard_shows_what_there_still_must_with_the_real_cron_line(): void
    {
        $this->actingAs($this->administrator())
            ->get('/admin')
            ->assertOk()
            ->assertSee(__('Finish the installation'))
            ->assertSee(__('Turn on the automatic tasks'))
            ->assertSee('php '.base_path('artisan').' schedule:run')
            ->assertSee(__('Set up the mailbox'));

        ScheduledTasks::tick();
        $this->mailbox();

        $this->assertSame([], FinishInstallation::steps());
        $this->get('/admin')->assertOk()->assertDontSee(__('Finish the installation'));
    }

    public function test_only_a_administrator_sees_the_checklist(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff, 'permissions' => ['accommodations' => Access::ACTIONS]]));

        $this->assertFalse(FinishInstallation::canView());
    }
}
