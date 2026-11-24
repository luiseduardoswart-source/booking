<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\InstallController;
use App\Http\Middleware\EnsureNotInstalled;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Support\InstallCode::cleanUp();
    }

    protected function tearDown(): void
    {
        \App\Support\InstallCode::cleanUp();

        parent::tearDown();
    }

    /** The wizard sits behind a code; skip that step here. */
    private function withCode(): static
    {
        return $this->withSession(['install_code_ok' => true]);
    }

    public function test_wizard_is_reachable_as_long_as_there_no_account_is(): void
    {
        // If the tables already exist (as here), step 1 is done and the
        // wizard goes straight on to creating the account.
        $this->withCode()->get('/install')->assertRedirect(route('install.account'));

        $this->withCode()->get('/install/account')
            ->assertOk()
            ->assertSee(__('Create account and sign in'));

        // Changing the database details afterwards is possible with ?opnieuw=1.
        $this->withCode()->get('/install?again=1')
            ->assertOk()
            ->assertSee(__('System check'))
            ->assertSee(__('Database name'));
    }

    public function test_wizard_closes_itself_as_soon_as_there_a_account_exists(): void
    {
        User::factory()->create();

        $this->assertTrue(EnsureNotInstalled::isInstalled());

        // all four routes must be closed, not only the first
        $this->get('/install')->assertRedirect('/admin');
        $this->post('/install')->assertRedirect('/admin');
        $this->get('/install/account')->assertRedirect('/admin');
        $this->post('/install/account')->assertRedirect('/admin');
    }

    public function test_a_live_site_address_must_be_on_https(): void
    {
        // The form suggests the https address, also when opened over http.
        $this->withCode()->get('http://www.example.com/install?again=1')
            ->assertSee('value="https://www.example.com"', false);

        $details = ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_database' => 'x', 'db_username' => 'x'];

        $this->withCode()->post('/install', [...$details, 'app_url' => 'http://www.example.com'])
            ->assertSessionHasErrors('app_url');

        // On this computer plain http is fine (not posted: that would try the database).
        $this->assertTrue(InstallController::secureAddress('http://localhost'));
        $this->assertTrue(InstallController::secureAddress('http://booking.test'));
        $this->assertTrue(InstallController::secureAddress('https://www.example.com'));
        $this->assertFalse(InstallController::secureAddress('http://www.example.com'));
    }

    public function test_first_account_is_administrator_and_is_logged_in(): void
    {
        $this->withCode()->post('/install/account', [
            'name' => 'Luis',
            'email' => 'luis@example.nl',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect('/admin');

        $user = User::firstWhere('email', 'luis@example.nl');

        $this->assertNotNull($user);
        // The owner must have access to the whole admin, so administrator.
        $this->assertSame(UserRole::Administrator, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_weak_password_and_double_email_address_are_rejected(): void
    {
        $this->withCode()->post('/install/account', [
            'name' => 'Luis',
            'email' => 'no-email-address',
            'password' => 'short',
            'password_confirmation' => 'otherwise',
        ])->assertSessionHasErrors(['email', 'password']);

        $this->assertSame(0, User::query()->count());
    }

    /** Reads the prefill, and then restores the database configuration. */
    private function defaultFor(array $config): array
    {
        $original = [
            'database.default' => config('database.default'),
            'database.connections.mysql' => config('database.connections.mysql'),
        ];

        try {
            config($config);

            $method = new \ReflectionMethod(\App\Http\Controllers\InstallController::class, 'existingSettings');
            $method->setAccessible(true);

            return $method->invoke(app(\App\Http\Controllers\InstallController::class));
        } finally {
            // Restoring is essential: RefreshDatabase runs a transaction on
            // the default connection and otherwise trips over the cleanup.
            config($original);
        }
    }

    public function test_existing_env_details_are_prefilled_but_the_password_never(): void
    {
        $default = $this->defaultFor([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => 'db.example.nl',
            'database.connections.mysql.database' => 'u123_booking',
            'database.connections.mysql.username' => 'u123_ubooking',
            'database.connections.mysql.password' => 'secret-password',
        ]);

        $this->assertSame('db.example.nl', $default['host']);
        $this->assertSame('u123_booking', $default['database']);
        $this->assertSame('u123_ubooking', $default['username']);
        // the password never comes back to be shown, only the notice that it is there
        $this->assertArrayNotHasKey('password', $default);
        $this->assertTrue($default['password_set']);
    }

    public function test_without_mysql_in_env_stay_the_fields_neutral(): void
    {
        // Do not show the framework defaults 'laravel'/'root': that would be
        // confusing on a fresh installation.
        $default = $this->defaultFor(['database.default' => 'sqlite']);

        $this->assertSame('localhost', $default['host']);
        $this->assertSame('', $default['database']);
        $this->assertSame('', $default['username']);
        $this->assertFalse($default['password_set']);
    }

    public function test_wizard_runs_without_the_site_middleware_that_the_database_queries(): void
    {
        // On a fresh installation the tables do not exist yet. Middleware that
        // fetches settings, pages or the logged-in user would make the wizard
        // crash before it can show anything.
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => $r->getName() === 'install.database');

        $excluded = $route->excludedMiddleware();

        $this->assertContains(\App\Http\Middleware\ApplySiteLayout::class, $excluded);
        $this->assertContains(\App\Http\Middleware\InjectStyleEditor::class, $excluded);
        $this->assertContains(\App\Http\Middleware\ApplyContentLinks::class, $excluded);
    }

    public function test_sessions_and_cache_are_on_files_so_that_the_wizard_without_tables_works(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('SESSION_DRIVER=file', $example);
        $this->assertStringContainsString('CACHE_STORE=file', $example);
    }

    public function test_uploads_countries_in_public_storage_so_that_storage_link_not_needed_is(): void
    {
        // On shared hosting symlink() and exec() are often disabled, so
        // `php artisan storage:link` cannot run. That is why the
        // public disk points directly to public/storage.
        $this->assertSame(public_path('storage'), config('filesystems.disks.public.root'));
    }

    public function test_the_deploy_script_runs_no_storage_link(): void
    {
        // On the hosting storage:link fails, and because of `set -e` deploy.sh would
        // then stop before the caches are refreshed.
        $script = file_get_contents(base_path('deploy.sh'));

        $this->assertDoesNotMatchRegularExpression('/^[^#\n]*artisan storage:link/m', $script);
    }

    public function test_install_is_a_reserved_slug_for_pages(): void
    {
        $reflection = new \ReflectionClass(\App\Filament\Resources\Pages\PageResource::class);

        $this->assertContains('install', $reflection->getConstant('RESERVED_SLUGS'));
    }

    public function test_wizard_sits_behind_the_install_code(): void
    {
        // Without code: every step redirects back to the code screen. This is the
        // protection that prevents a random visitor from making themselves
        // administrator as long as there is no account yet.
        $this->get('/install')->assertRedirect(route('install.code'));
        $this->get('/install/account')->assertRedirect(route('install.code'));
        $this->post('/install/account', [
            'name' => 'Indringer',
            'email' => 'intruder@example.nl',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect(route('install.code'));

        $this->assertSame(0, User::query()->count());
    }

    public function test_code_is_created_in_a_file_outside_the_web_dir(): void
    {
        $this->get('/install/code')->assertOk()->assertSee(\App\Support\InstallCode::FILE);

        $path = \App\Support\InstallCode::path();

        $this->assertFileExists($path);
        $this->assertSame(32, strlen(\App\Support\InstallCode::current()));
        // outside public/, so never retrievable via a URL
        $this->assertStringNotContainsString(public_path(), $path);
    }

    public function test_right_code_gives_access_and_a_wrong_not(): void
    {
        $code = \App\Support\InstallCode::ensureCode();

        $this->post('/install/code', ['code' => 'error'])
            ->assertSessionHasErrors('code');
        $this->assertNull(session('install_code_ok'));

        $this->post('/install/code', ['code' => $code])
            ->assertRedirect(route('install.database'));
        $this->assertTrue(session('install_code_ok'));
    }

    public function test_guessing_is_throttled_after_five_attempts(): void
    {
        \App\Support\InstallCode::ensureCode();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/install/code', ['code' => 'error-'.$attempt])->assertSessionHasErrors('code');
        }

        $response = $this->post('/install/code', ['code' => 'error-6']);

        $response->assertSessionHasErrors('code');

        // Tests run in English, so compare with the translated text.
        $template = __('Too many attempts. Try again in :seconds seconds.', ['seconds' => '%s']);
        $this->assertStringContainsString(
            substr($template, 0, strpos($template, '%s')),
            session('errors')->first('code'),
        );

        \Illuminate\Support\Facades\RateLimiter::clear('install_code:127.0.0.1');
    }

    public function test_code_disappears_as_soon_as_the_installation_ready_is(): void
    {
        \App\Support\InstallCode::ensureCode();
        $this->assertFileExists(\App\Support\InstallCode::path());

        $this->withCode()->post('/install/account', [
            'name' => 'Luis',
            'email' => 'luis@example.nl',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect('/admin');

        $this->assertFileDoesNotExist(\App\Support\InstallCode::path());
    }

    public function test_database_outage_applies_not_as_not_installed(): void
    {
        // Otherwise a temporary outage would send all visitors to the installation
        // instead of showing an ordinary error message.
        $outage = new \Illuminate\Database\QueryException(
            'mysql', 'select 1', [], new \PDOException('SQLSTATE[HY000] [2002] Connection refused')
        );

        $method = new \ReflectionMethod(EnsureNotInstalled::class, 'tableIsMissing');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, $outage));
    }

    public function test_without_tables_comes_the_visitor_at_the_wizard(): void
    {
        // Fresh installation: no tables yet. The site cannot show anything then,
        // so the visitor goes to the wizard instead of a database error.
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        \Illuminate\Support\Facades\Schema::drop('users');

        $this->assertFalse(EnsureNotInstalled::databaseIsSetUp());

        $this->get('/')->assertRedirect(route('install.database'));
    }

    public function test_running_site_without_user_stays_simply_reachable(): void
    {
        // Important difference: the tables do exist, there is just (temporarily) no
        // administrator. A live site must not suddenly send its visitors to
        // an installation screen because of that.
        $this->assertSame(0, User::query()->count());
        $this->assertTrue(EnsureNotInstalled::databaseIsSetUp());

        $this->get('/')->assertOk();
    }

    public function test_company_email_address_is_filled_with_that_of_the_administrator(): void
    {
        // Otherwise contact forms and conflict notices arrive nowhere.
        $this->withCode()->post('/install/account', [
            'name' => 'Luis',
            'email' => 'luis@example.nl',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect('/admin');

        $this->assertSame('luis@example.nl', \App\Models\Setting::get('company', 'email'));
    }

    public function test_a_already_filled_in_company_address_stays_are(): void
    {
        \App\Models\Setting::set('company', 'email', 'bookings@example.nl');

        $this->withCode()->post('/install/account', [
            'name' => 'Luis',
            'email' => 'luis@example.nl',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect('/admin');

        $this->assertSame('bookings@example.nl', \App\Models\Setting::get('company', 'email'));
    }

    public function test_company_fields_are_not_open_for_automatic_fill_in(): void
    {
        // Otherwise Chrome sees this as an address form and fills in, for example, an
        // email address in the IBAN field.
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $html = $this->actingAs($admin)->get(\App\Filament\Pages\Settings::getUrl())->getContent();

        foreach (['company_email', 'company_iban', 'company_address', 'company_postal_code_city'] as $field) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($field, '/').'.{0,2000}?autocomplete="off"/s',
                $html,
                "Veld {$field} staat open voor automatisch invullen",
            );
        }
    }
}
