<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\WebsiteTextsAdmin;
use App\Models\Host;
use App\Models\Language;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Support\WebsiteTexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The fixed words of the website can be adjusted per language in the admin,
 * without the admin itself changing.
 */
class WebsiteTextsTest extends TestCase
{
    use RefreshDatabase;

    private const WORD = 'per night';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator, 'locale' => 'es']);
    }

    private function hash(string $key = self::WORD): string
    {
        return sha1($key);
    }

    private function accommodation(): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    public function test_the_list_contains_the_texts_of_the_website_and_not_of_the_admin(): void
    {
        $all = WebsiteTexts::all();

        $this->assertArrayHasKey($this->hash(), $all);
        $this->assertSame('Website', $all[$this->hash()]['kind']);
        $this->assertArrayNotHasKey(sha1('Complete language files'), $all);
        $this->assertArrayNotHasKey(sha1('...'), $all);
        $this->assertGreaterThan(200, count($all));
    }

    public function test_a_adjusted_word_appears_on_the_website(): void
    {
        $this->accommodation();

        Livewire::actingAs($this->owner())->test(WebsiteTextsAdmin::class)
            ->call('save', $this->hash(), 'es', 'Mira si hay sitio')
            ->assertOk();

        $this->get('/es/strandhuis')
            ->assertOk()
            ->assertSee('Mira si hay sitio')
            ->assertDontSee('por noche');
    }

    public function test_a_other_language_changes_not_along(): void
    {
        $this->accommodation();
        $english = trans(self::WORD, [], 'en');

        WebsiteTexts::save(self::WORD, 'es', 'Mira si hay sitio');

        $this->get('/en/strandhuis')->assertSee($english)->assertDontSee('Mira si hay sitio');
    }

    public function test_the_admin_stays_the_text_off_the_file_use(): void
    {
        $owner = $this->owner();
        $key = 'Boekingen';
        $file = WebsiteTexts::default($key, 'es');
        WebsiteTexts::save($key, 'es', 'Mis reservas');

        $this->actingAs($owner)->get('/admin')->assertOk();

        $this->assertSame($file, __($key));
    }

    public function test_the_website_used_the_text_again_after_a_request_on_the_admin(): void
    {
        $this->accommodation();
        WebsiteTexts::save(self::WORD, 'es', 'Mira si hay sitio');

        $this->actingAs($this->owner())->get('/admin')->assertOk();

        $this->get('/es/strandhuis')->assertSee('Mira si hay sitio');
    }

    public function test_empty_set_the_default_text_back(): void
    {
        WebsiteTexts::save(self::WORD, 'es', 'Mira si hay sitio');
        WebsiteTexts::save(self::WORD, 'es', '   ');

        $this->assertSame([], WebsiteTexts::custom('es'));
        $this->assertNull(Setting::get('website_texts', 'es'));
    }

    public function test_equal_on_the_file_is_not_separate_saved(): void
    {
        WebsiteTexts::save(self::WORD, 'es', WebsiteTexts::default(self::WORD, 'es'));

        $this->assertSame([], WebsiteTexts::custom('es'));
    }

    public function test_placeholders_must_stay_are(): void
    {
        $key = 'Verblijf (:nights nachten)';

        $this->assertNotNull(WebsiteTexts::save($key, 'es', 'Estancia'));
        $this->assertSame([], WebsiteTexts::custom('es'));

        $this->assertNull(WebsiteTexts::save($key, 'es', 'Estancia de :nights noches'));
        $this->assertSame('Estancia de 3 noches', trans($key, ['nights' => 3], 'es'));
    }

    public function test_plural_forms_must_be_correct(): void
    {
        $key = '{1} 1 review|[2,*] :count reviews';

        $this->assertNotNull(WebsiteTexts::save($key, 'es', ':count opiniones'));
        $this->assertNull(WebsiteTexts::save($key, 'es', '{1} 1 opinión|[2,*] :count opiniones'));
        $this->assertSame('3 opiniones', trans_choice($key, 3, [], 'es'));
    }

    public function test_html_in_a_regular_text_is_deleted(): void
    {
        WebsiteTexts::save(self::WORD, 'es', '<script>alert(1)</script>Mira');

        $this->assertSame('alert(1)Mira', WebsiteTexts::custom('es')[self::WORD]);
    }

    public function test_only_existing_texts_can_are_saved(): void
    {
        Livewire::actingAs($this->owner())->test(WebsiteTextsAdmin::class)
            ->call('save', sha1('Bestaat niet'), 'es', 'x')
            ->assertNotFound();

        Livewire::actingAs($this->owner())->test(WebsiteTextsAdmin::class)
            ->call('save', $this->hash(), 'xx', 'x')
            ->assertNotFound();
    }

    public function test_a_host_can_the_texts_not_adjust(): void
    {
        $host = User::factory()->create([
            'role' => UserRole::Host, 'host_id' => Host::create(['name' => 'Host'])->id, 'permissions' => [],
        ]);

        Livewire::actingAs($host)->test(WebsiteTextsAdmin::class)->assertForbidden();

        $this->assertSame([], WebsiteTexts::custom('es'));
    }

    public function test_search_filters_on_every_language(): void
    {
        $component = Livewire::actingAs($this->owner())->test(WebsiteTextsAdmin::class);

        $english = trans(self::WORD, [], 'en');

        $component->set('search', $english)->assertSeeHtml('value="'.e($english).'"')->assertDontSeeHtml('value="Annuleringsvoorwaarden"');
        $component->set('search', 'exists-nowhere-xyz')->assertSee(__('No texts found.'));
    }

    public function test_only_active_languages_are_in_the_tab(): void
    {
        Language::query()->where('code', 'zh')->update(['active' => false]);
        Language::forgets();

        Livewire::actingAs($this->owner())->test(WebsiteTextsAdmin::class)
            ->assertSee('Español')
            ->assertDontSee('中文');
    }

    public function test_a_broken_value_puts_the_site_not_flat(): void
    {
        Setting::set('website_texts', 'es', 'geen json');

        $this->assertSame([], WebsiteTexts::custom('es'));
        $this->get('/es')->assertOk();
    }
}
