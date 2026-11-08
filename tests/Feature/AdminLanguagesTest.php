<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Enums\AdminNotification;
use App\Enums\UserRole;
use App\Filament\Auth\Profile;
use App\Filament\Pages\Settings;
use App\Mail\AdminNotificationMail;
use App\Models\AdminLanguage;
use App\Models\Booking;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use App\Support\AdminLanguages;
use App\Support\Languages;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

/**
 * The admin has its own languages. What the owner switches on or off, adds or
 * removes on the website does not affect the admin, and vice versa.
 */
class AdminLanguagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(?string $locale = 'nl'): User
    {
        return User::factory()->create(['role' => UserRole::Administrator, 'locale' => $locale]);
    }

    /** @param array<int, string> $codes */
    private function siteLanguages(array $codes): void
    {
        Language::query()->update(['active' => false, 'is_default' => false]);
        Language::query()->whereIn('code', $codes)->update(['active' => true]);
        Language::query()->where('code', $codes[0])->update(['is_default' => true]);
        Language::forgets();
    }

    /** @param array<int, string> $codes */
    private function adminLanguages(array $codes): void
    {
        AdminLanguage::query()->update(['active' => false, 'is_default' => false]);
        AdminLanguage::query()->whereIn('code', $codes)->update(['active' => true]);
        AdminLanguage::query()->where('code', $codes[0])->update(['is_default' => true]);
        AdminLanguage::forgets();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function saveAdminLanguages(array $rows, string $default): void
    {
        Livewire::actingAs($this->owner())
            ->test(Settings::class)
            ->fillForm([
                'company_name' => 'Testverhuur',
                'admin_languages' => $rows,
                'admin_default_language' => $default,
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_migration_takes_every_language_of_the_site_on_along(): void
    {
        $this->assertSame(Languages::available(), AdminLanguages::available());
        $this->assertSame(AdminLanguages::available(), AdminLanguages::active());
        $this->assertSame(config('app.locale'), AdminLanguages::default());
    }

    public function test_a_language_switch_off_on_the_site_affects_the_admin_not(): void
    {
        $this->siteLanguages(['en', 'es']);

        $this->actingAs($this->owner('nl'))->get('/admin')->assertOk()->assertSee('lang="nl"', false);
    }

    public function test_the_language_choice_on_the_site_affects_the_admin_not(): void
    {
        $this->actingAs($this->owner('nl'))->get('/language/es')->assertRedirect();

        $this->get('/admin')->assertOk()->assertSee('lang="nl"', false);
    }

    public function test_the_language_of_the_admin_affects_the_site_not(): void
    {
        $this->siteLanguages(['es', 'en']);

        $this->actingAs($this->owner('en'))
            ->withHeaders(['Accept-Language' => 'es'])
            ->get('/');

        $this->assertSame('es', app()->getLocale());
    }

    public function test_a_language_switch_off_in_the_admin_affects_the_site_not(): void
    {
        $this->adminLanguages(['en']);

        $this->actingAs($this->owner('nl'))->get('/admin')->assertOk()->assertSee('lang="en"', false);

        $this->assertContains('nl', Languages::active());
        $this->get('/language/nl')->assertRedirect();
    }

    public function test_also_a_livewire_request_stays_in_the_language_of_the_admin(): void
    {
        $this->siteLanguages(['en', 'es']);
        $owner = $this->owner('nl');

        $page = $this->actingAs($owner)->get('/admin')->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $page, $hit);
        $this->assertNotEmpty($hit, 'no Livewire component on the page');

        $this->withHeaders(['X-Livewire' => '1'])
            ->postJson(app(HandleRequests::class)->getUpdateUri(), [
                '_token' => csrf_token(),
                'components' => [['snapshot' => html_entity_decode($hit[1]), 'updates' => (object) [], 'calls' => []]],
            ])
            ->assertOk();

        // Runs via the web group (the site's language), but Livewire restores
        // the language of the page the button was on; Carbon follows along.
        $this->assertSame('nl', app()->getLocale());
        $this->assertSame('nl', Carbon::getLocale());
    }

    public function test_admin_chooses_the_browser_language_on_the_login_screen_and_otherwise_the_default(): void
    {
        $this->adminLanguages(['nl', 'de', 'es']);

        $this->withHeaders(['Accept-Language' => 'de-DE,de;q=0.9'])->get('/admin/login')->assertOk();
        $this->assertSame('de', app()->getLocale());

        $this->withHeaders(['Accept-Language' => 'fr-FR'])->get('/admin/login')->assertOk();
        $this->assertSame('nl', app()->getLocale());
    }

    public function test_a_language_add_on_the_admin_comes_not_on_the_site(): void
    {
        $this->saveAdminLanguages([
            ['code' => 'nl', 'name' => 'Nederlands', 'active' => true],
            ['code' => 'it', 'name' => 'Italiano', 'active' => true],
        ], 'it');

        $this->assertSame(['nl', 'it'], AdminLanguages::available());
        $this->assertSame('it', AdminLanguages::default());
        $this->assertSame('Italiano', AdminLanguages::name('it'));

        $this->assertNotContains('it', Languages::available());
        $this->get('/language/it')->assertNotFound();
    }

    public function test_the_lists_save_separate_of_each_other(): void
    {
        $siteFor = Language::query()->orderBy('id')->get(['code', 'active', 'is_default'])->toArray();

        $this->saveAdminLanguages([['code' => 'nl', 'name' => 'Vlaams', 'active' => true]], 'nl');

        $this->assertSame($siteFor, Language::query()->orderBy('id')->get(['code', 'active', 'is_default'])->toArray());
        $this->assertSame('Vlaams', AdminLanguages::name('nl'));
        $this->assertSame('Nederlands', Languages::name('nl'));

        $adminFor = AdminLanguage::query()->orderBy('id')->pluck('code')->all();

        Livewire::actingAs($this->owner())
            ->test(Settings::class)
            ->fillForm([
                'company_name' => 'Testverhuur',
                'languages' => [['code' => 'en', 'name' => 'English', 'active' => true]],
                'site_default_language' => 'en',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['en'], Languages::available());
        $this->assertSame($adminFor, AdminLanguage::query()->orderBy('id')->pluck('code')->all());
    }

    public function test_a_language_code_may_no_path_or_foreign_characters_contain(): void
    {
        Livewire::actingAs($this->owner())
            ->test(Settings::class)
            ->fillForm([
                'company_name' => 'Testverhuur',
                'admin_languages' => [['code' => '../x', 'name' => 'Stiekem', 'active' => true]],
                'admin_default_language' => '../x',
            ])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertNull(AdminLanguage::firstWhere('code', '../x'));
    }

    public function test_the_language_switch_shows_the_languages_of_the_admin_and_not_that_of_the_site(): void
    {
        $this->siteLanguages(['en', 'es']);
        $this->adminLanguages(['nl', 'de']);

        $this->actingAs($this->owner('nl'))->get('/admin')
            ->assertOk()
            ->assertSee(route('filament.admin.language', 'nl'), false)
            ->assertSee(route('filament.admin.language', 'de'), false)
            ->assertDontSee(route('filament.admin.language', 'es'), false);
    }

    public function test_the_language_switch_rejects_a_language_that_not_at_the_admin_belongs(): void
    {
        $this->adminLanguages(['nl', 'de']);
        $owner = $this->owner('nl');

        $this->actingAs($owner)->get(route('filament.admin.language', 'es'))->assertNotFound();

        $this->assertSame('nl', $owner->fresh()->locale);
    }

    public function test_the_language_switch_is_there_only_for_who_is_logged_in(): void
    {
        $this->get(route('filament.admin.language', 'de'))->assertRedirect('/admin/login');
    }

    public function test_the_own_language_chooses_off_the_languages_of_the_admin(): void
    {
        $this->siteLanguages(['en']);
        $this->adminLanguages(['nl', 'de']);
        $owner = $this->owner('nl');

        Livewire::actingAs($owner)->test(Profile::class)
            ->fillForm(['locale' => 'de'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('de', $owner->fresh()->locale);

        Livewire::actingAs($owner)->test(Profile::class)
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasFormErrors(['locale']);
        $this->assertSame('de', $owner->fresh()->locale);
    }

    public function test_mail_without_own_language_goes_in_the_default_language_of_the_admin(): void
    {
        $this->adminLanguages(['de', 'nl']);

        $this->assertSame('de', $this->owner(null)->preferredLocale());

        $accommodation = Property::create([
            'name' => 'Casa', 'slug' => 'casa', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000,
        ]);
        $booking = new Booking(['property_id' => $accommodation->id]);

        $this->assertSame('de', (new AdminNotificationMail(AdminNotification::NewBooking, $booking))->locale);
    }

    public function test_a_booking_off_the_admin_gets_never_a_language_that_on_the_site_is_off(): void
    {
        Mail::fake();
        $this->siteLanguages(['es', 'en']);
        $accommodation = Property::create([
            'name' => 'Casa', 'slug' => 'casa', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000,
        ]);

        app()->setLocale('nl'); // the admin works in Dutch

        $booking = app(BookingEngine::class)->create($accommodation, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-04'), 2, ['name' => 'Jan', 'email' => 'jan@example.com']);

        $this->assertSame('es', $booking->locale);
    }

    public function test_a_added_language_on_the_site_is_also_to_choose(): void
    {
        Language::create(['code' => 'it', 'name' => 'Italiano', 'active' => true, 'sort' => 9]);

        $this->get('/language/it')->assertRedirect();
        $this->get('/');

        $this->assertSame('it', app()->getLocale());
    }

    public function test_a_visitor_without_matching_browser_language_gets_the_default_language(): void
    {
        $this->siteLanguages(['es', 'en']);
        Language::query()->update(['is_default' => false]);
        Language::query()->where('code', 'en')->update(['is_default' => true]);
        Language::query()->where('code', 'es')->update(['sort' => -1]);
        Language::forgets();

        $this->withHeaders(['Accept-Language' => 'fr-FR'])->get('/');

        $this->assertSame('en', app()->getLocale());
    }
}
