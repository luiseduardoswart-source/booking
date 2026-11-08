<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\AdminLanguage;
use App\Models\Amenity;
use App\Models\Language;
use App\Models\User;
use App\Support\AdminLanguages;
use App\Support\InstallCode;
use App\Support\Languages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class LanguagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sets the languages as the administrator would: only these codes active,
     * the first as default.
     *
     * @param  array<int, string>  $codes
     */
    private function suppose(array $codes, ?string $default = null): void
    {
        Language::query()->update(['active' => false, 'is_default' => false]);

        foreach ($codes as $i => $code) {
            Language::query()->where('code', $code)->update([
                'active' => true,
                'is_default' => $code === ($default ?? $codes[0]),
                'sort' => $i,
            ]);
        }

        Language::forgets();
    }

    public function test_without_setting_are_all_languages_on(): void
    {
        $this->assertSame(Languages::available(), Languages::active());
    }

    public function test_only_the_chosen_languages_are_active(): void
    {
        $this->suppose(['en', 'es']);

        $this->assertSame(['en', 'es'], Languages::active());
    }

    public function test_a_itself_added_language_appears_on_the_site(): void
    {
        Language::create(['code' => 'it', 'name' => 'Italiano', 'active' => true, 'sort' => 9]);
        Language::forgets();

        $this->assertContains('it', Languages::active());
        $this->assertSame('Italiano', Languages::name('it'));
        $this->get('/')->assertSee(route('locale.switch', 'it'));
    }

    public function test_a_renamed_language_gets_her_own_name(): void
    {
        Language::query()->where('code', 'en')->update(['name' => 'English (UK)']);
        Language::forgets();

        $this->assertSame('English (UK)', Languages::name('en'));
    }

    public function test_the_default_language_is_there_always_at(): void
    {
        // Otherwise the site would open in a language nobody can choose.
        $this->suppose(['es']);

        // Default language that was switched off by accident: the site must still be able
        // to fall back on it, so it belongs among the active languages.
        Language::query()->update(['is_default' => false]);
        Language::query()->where('code', 'en')->update(['active' => false, 'is_default' => true]);
        Language::forgets();

        $this->assertContains('en', Languages::active());
    }

    public function test_without_default_language_is_the_first_active_used(): void
    {
        Language::query()->update(['is_default' => false]);
        Language::forgets();

        $this->assertContains(Languages::default(), Languages::available());
    }

    public function test_the_visitor_gets_the_default_language_as_are_browser_language_is_off(): void
    {
        $this->suppose(['en']);

        $this->withHeaders(['Accept-Language' => 'nl-NL,nl;q=0.9'])->get('/');

        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_language_picker_shows_only_active_languages(): void
    {
        $this->suppose(['nl', 'en']);

        $answer = $this->get('/');

        $answer->assertSee(route('locale.switch', 'en'));
        $answer->assertDontSee(route('locale.switch', 'zh'));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function save(array $rows, string $default): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(Settings::class)
            ->fillForm([
                'company_name' => 'Testverhuur',
                'languages' => $rows,
                'site_default_language' => $default,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Language::forgets();
    }

    public function test_administrator_adds_a_language(): void
    {
        $this->save([
            ['code' => 'nl', 'name' => 'Nederlands', 'active' => true],
            ['code' => 'it', 'name' => 'Italiano', 'active' => true],
        ], 'nl');

        $this->assertSame(['nl', 'it'], Languages::available());
        $this->assertSame('Italiano', Languages::name('it'));
    }

    public function test_administrator_renames_a_language(): void
    {
        $this->save([['code' => 'nl', 'name' => 'Vlaams', 'active' => true]], 'nl');

        $this->assertSame('Vlaams', Languages::name('nl'));
    }

    public function test_removed_languages_disappear_really(): void
    {
        $this->save([
            ['code' => 'nl', 'name' => 'Nederlands', 'active' => true],
            ['code' => 'en', 'name' => 'English', 'active' => true],
        ], 'nl');

        $this->assertSame(['nl', 'en'], Languages::available());
        $this->assertNull(Language::firstWhere('code', 'zh'));
    }

    public function test_the_default_language_is_always_visible_set(): void
    {
        // Default is English, but the "on the site" checkbox was accidentally off.
        $this->save([
            ['code' => 'nl', 'name' => 'Nederlands', 'active' => true],
            ['code' => 'en', 'name' => 'English', 'active' => false],
        ], 'en');

        $this->assertTrue(Language::firstWhere('code', 'en')->active);
    }

    /**
     * Locally the cache runs in memory, live it writes to disk.
     * There a cached Eloquent collection broke on serialisation and gave
     * the whole settings screen a 500. This test therefore runs with a
     * real file cache.
     */
    public function test_the_language_list_survives_a_cache_that_to_disk_writes(): void
    {
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => storage_path('framework/cache/languages-test'),
        ]);
        Language::forgets();

        // First call fills the cache, second reads it back from disk.
        $first = Language::allCached();
        $second = Language::allCached();

        $this->assertInstanceOf(Language::class, $second->first());
        $this->assertSame($first->pluck('code')->all(), $second->pluck('code')->all());
        $this->assertIsBool($second->first()->active);
        $this->assertContains(Languages::default(), Languages::available());

        // The core of the fix: the cache holds flat rows, not objects.
        $this->assertIsArray(Cache::get('languages.all'));
        $this->assertIsArray(Cache::get('languages.all')[0]);

        Language::forgets();
    }

    public function test_a_unreadable_cache_puts_the_languages_not_flat(): void
    {
        Cache::forever('languages.all', 'iets onbruikbaars');

        // Without catching this, a string would come out of the cache here and every
        // screen that requests the languages would crash.
        $this->assertInstanceOf(Language::class, Language::allCached()->first());
        $this->assertContains('nl', Languages::available());
    }

    public function test_a_fresh_installation_has_default_amenities(): void
    {
        $this->assertSame(count(Amenity::DEFAULT), Amenity::query()->count());
        $this->assertNotNull(Amenity::firstWhere('name', 'Beach access'));
    }

    public function test_amenities_get_their_icon_along(): void
    {
        $this->assertSame('bubbles', Amenity::firstWhere('name', 'Hot tub')->iconName());
    }

    /**
     * Without a matching browser language, visitors and admins see English:
     * the wizard makes it the default of both, whatever APP_LOCALE says.
     */
    public function test_the_wizard_makes_english_the_default_of_website_and_admin(): void
    {
        Language::query()->update(['is_default' => false]);
        Language::query()->where('code', 'nl')->update(['is_default' => true]);
        AdminLanguage::query()->update(['is_default' => false]);
        AdminLanguage::query()->where('code', 'nl')->update(['is_default' => true]);
        Language::forgets();
        AdminLanguage::forgets();
        InstallCode::ensureCode();

        $this->withSession(['install_code_ok' => true])->get('/install/account')
            ->assertOk()
            ->assertSee('name="password"', false)
            ->assertDontSee('site_language');

        $this->withSession(['install_code_ok' => true])->post('/install/account', [
            'name' => 'Luis',
            'email' => 'luis@example.nl',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect('/admin');

        $this->assertSame('en', Languages::default());
        $this->assertSame('en', AdminLanguages::default());
        $this->assertSame(['en'], Language::where('is_default', true)->pluck('code')->all());
        $this->assertSame(['en'], AdminLanguage::where('is_default', true)->pluck('code')->all());

        // A Dutch visitor still gets Dutch while Dutch is on.
        $this->withHeader('Accept-Language', 'nl-NL,nl;q=0.9')->get('/')->assertSee('<html lang="nl"', false);
    }
}
