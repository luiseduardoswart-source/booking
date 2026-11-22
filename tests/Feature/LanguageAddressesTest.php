<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Page;
use App\Models\Property;
use App\Support\Slugs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every language its own address, so search engines can find each one: the
 * default language (English here) without a prefix, every other language
 * under its code: /es, /es/casa-frente-al-mar, /es/contact.
 */
class LanguageAddressesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // English is the default language here: its addresses have no prefix.
        \App\Models\Language::query()->update(['is_default' => false]);
        \App\Models\Language::query()->where('code', 'en')->update(['is_default' => true, 'active' => true]);
        \App\Models\Language::forgets();
    }

    private function accommodation(array $extra = []): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'NL', 'cancellation_policy' => 'moderate',
            'name_translations' => ['es' => 'Casa frente al mar'],
            'slug_translations' => ['es' => 'casa-frente-al-mar', 'nl' => 'house-nl'],
            ...$extra,
        ]);
    }

    public function test_every_language_has_its_own_address(): void
    {
        $this->accommodation();

        $this->get('/')->assertOk()->assertSee('lang="en"', false);
        $this->get('/es')->assertOk()->assertSee('lang="es"', false);
        $this->get('/es/contact')->assertOk()->assertSee('lang="es"', false);
        $this->get('/es/casa-frente-al-mar')->assertOk()->assertSee('lang="es"', false)->assertSee('Casa frente al mar');
        $this->get('/nl/house-nl')->assertOk()->assertSee('lang="nl"', false);
        // A language without its own slug uses the base slug under its prefix.
        $this->get('/de/strandhuis')->assertOk()->assertSee('lang="de"', false);
    }

    public function test_a_search_engine_without_a_language_gets_the_default(): void
    {
        $this->accommodation();

        // No session and no browser language, as a crawler arrives.
        $this->get('/strandhuis')->assertOk()->assertSee('lang="en"', false);
    }

    public function test_old_and_wrong_addresses_move_permanently(): void
    {
        $this->accommodation();

        // The translated address without the language in front (the old scheme).
        $this->get('/casa-frente-al-mar?guests=2')->assertStatus(301)->assertRedirect(url('es/casa-frente-al-mar').'?guests=2');
        // The base slug under a language that has its own.
        $this->get('/es/strandhuis')->assertStatus(301)->assertRedirect(url('es/casa-frente-al-mar'));
        // The default language never has a prefix.
        $this->get('/en')->assertStatus(301)->assertRedirect(url('/'));
        $this->get('/en/strandhuis')->assertStatus(301)->assertRedirect(url('strandhuis'));
    }

    public function test_a_switched_off_language_goes_to_the_default(): void
    {
        $this->accommodation();
        Language::query()->where('code', 'es')->update(['active' => false]);
        Language::forgets();

        $this->get('/es/casa-frente-al-mar')->assertStatus(301)->assertRedirect(url('strandhuis'));
    }

    public function test_a_visitor_goes_to_the_address_of_their_language(): void
    {
        $this->accommodation();

        $this->withSession(['locale' => 'es'])->get('/strandhuis?guests=2')
            ->assertStatus(302)->assertRedirect(url('es/casa-frente-al-mar').'?guests=2');

        // The browser language alone sends nobody elsewhere (Google advises against
        // it): the address decides, and the language switcher is there.
        $this->flushSession();
        $this->withHeaders(['Accept-Language' => 'es'])->get('/')->assertOk()->assertSee('lang="en"', false);
    }

    public function test_the_language_switcher_goes_to_the_address_of_the_chosen_language(): void
    {
        $this->accommodation();

        $this->from(url('es/casa-frente-al-mar').'?guests=2')->get('/language/nl')->assertRedirect(url('nl/house-nl').'?guests=2');
        $this->from(url('nl/house-nl'))->get('/language/en')->assertRedirect(url('strandhuis'));
        $this->get('/strandhuis')->assertOk()->assertSee('lang="en"', false);
        $this->from(url('strandhuis'))->get('/language/de')->assertRedirect(url('de/strandhuis'));
        $this->from(url('de/contact'))->get('/language/es')->assertRedirect(url('es/contact'));
        $this->from(url('es'))->get('/language/en')->assertRedirect(url('/'));
    }

    public function test_links_on_the_site_carry_the_language(): void
    {
        $this->accommodation();
        $this->accommodation(['slug' => 'second', 'name' => 'Tweede', 'name_translations' => null, 'slug_translations' => null]);

        $this->get('/es')->assertOk()
            ->assertSee('href="'.url('es/casa-frente-al-mar'), false)
            ->assertSee('href="'.url('es/second'), false)
            ->assertSee('href="'.url('es/contact').'"', false)
            ->assertDontSee('href="'.url('strandhuis'), false);
    }

    public function test_booking_keeps_working_from_a_language_address(): void
    {
        $this->accommodation();

        $this->get('/es/casa-frente-al-mar')->assertOk();
        $this->get('/casa-frente-al-mar/book?arrival=2027-03-01&departure=2027-03-04&guests=2')
            ->assertOk()->assertSee('lang="es"', false);
    }

    public function test_pages_have_an_address_per_language(): void
    {
        Page::create(['title' => 'About us', 'slug' => 'about-us', 'published' => true, 'title_translations' => ['es' => 'Sobre nosotros']]);

        $this->get('/es/sobre-nosotros')->assertOk()->assertSee('lang="es"', false)->assertSee('Sobre nosotros');
        $this->get('/sobre-nosotros')->assertStatus(301)->assertRedirect(url('es/sobre-nosotros'));

        // A visitor without the Spanish choice (as a search engine) gets the default.
        $this->flushSession();
        $this->get('/about-us')->assertOk()->assertSee('lang="en"', false);
    }

    public function test_search_engines_get_every_language_of_every_page(): void
    {
        $this->accommodation();

        $this->get('/es/casa-frente-al-mar?guests=2')
            ->assertSee('<link rel="canonical" href="'.url('es/casa-frente-al-mar').'">', false)
            ->assertSee('hreflang="es" href="'.url('es/casa-frente-al-mar').'"', false)
            ->assertSee('hreflang="en" href="'.url('strandhuis').'"', false)
            ->assertSee('hreflang="nl" href="'.url('nl/house-nl').'"', false)
            ->assertSee('hreflang="de" href="'.url('de/strandhuis').'"', false)
            ->assertSee('hreflang="x-default" href="'.url('strandhuis').'"', false);

        // The home page and contact too, and filters are not part of the canonical address.
        $this->get('/es?guests=2')
            ->assertSee('<link rel="canonical" href="'.url('es').'">', false)
            ->assertSee('hreflang="x-default" href="'.url('/').'"', false);
        $this->flushSession();
        $this->get('/contact')->assertSee('hreflang="fr" href="'.url('fr/contact').'"', false);
    }

    public function test_a_language_code_cannot_be_an_address(): void
    {
        $this->assertTrue(Slugs::occupied('es'));
        $this->assertTrue(Slugs::occupied('nl'));
        $this->assertFalse(Slugs::occupied('espana'));
    }
}
