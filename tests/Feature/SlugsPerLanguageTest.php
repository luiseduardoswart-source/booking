<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Language;
use App\Models\Page;
use App\Models\Property;
use App\Models\User;
use App\Support\Slugs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every language its own address: /casa-frente-al-mar opens the accommodation in
 * Spanish, and the language switcher takes you to the address of the chosen language.
 */
class SlugsPerLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function accommodation(array $extra = []): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'NL', 'cancellation_policy' => 'moderate',
            'name_translations' => ['es' => 'Casa frente al mar'],
            'slug_translations' => ['es' => 'casa-frente-al-mar', 'nl' => 'house-nl', 'de' => null],
            ...$extra,
        ]);
    }

    private function page(): Page
    {
        return Page::create([
            'title' => 'Over ons', 'slug' => 'in-our', 'published' => true,
            'title_translations' => ['es' => 'Sobre nosotros'],
        ]);
    }

    public function test_a_translated_address_opens_the_page_in_that_language(): void
    {
        $this->accommodation();

        $this->withHeaders(['Accept-Language' => 'nl'])->get('/casa-frente-al-mar')
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Casa frente al mar');
    }

    public function test_the_language_of_the_address_stays_apply_on_the_rest_of_the_site(): void
    {
        $this->accommodation();

        $this->get('/casa-frente-al-mar');
        $this->get('/contact')->assertOk()->assertSee('lang="es"', false);
    }

    public function test_the_base_address_goes_through_to_the_address_of_the_language(): void
    {
        $this->accommodation();

        $this->withSession(['locale' => 'es'])->get('/strandhuis?guests=2')
            ->assertRedirect(url('casa-frente-al-mar').'?guests=2');
    }

    public function test_the_base_address_stays_are_for_a_language_without_own_address(): void
    {
        $this->accommodation();

        $this->withSession(['locale' => 'de'])->get('/strandhuis')->assertOk()->assertSee('lang="de"', false);
    }

    public function test_the_language_switcher_goes_to_the_address_of_the_chosen_language(): void
    {
        $this->accommodation();

        $this->get('/casa-frente-al-mar');

        $this->from(url('casa-frente-al-mar').'?guests=2')->get('/language/nl')
            ->assertRedirect(url('house-nl').'?guests=2');
        $this->get('/house-nl')->assertOk()->assertSee('lang="nl"', false);

        $this->from(url('house-nl'))->get('/language/en')->assertRedirect(url('strandhuis'));
        $this->get('/strandhuis')->assertOk()->assertSee('lang="en"', false);

        $this->from(url('strandhuis'))->get('/language/de')->assertRedirect(url('strandhuis'));
        $this->get('/strandhuis')->assertOk()->assertSee('lang="de"', false);
    }

    public function test_the_language_switcher_lets_other_pages_with_rest(): void
    {
        $this->from(url('contact'))->get('/language/es')->assertRedirect(url('contact'));
        $this->from(url('/'))->get('/language/en')->assertRedirect(url('/'));
    }

    public function test_book_works_on_the_translated_address(): void
    {
        $this->accommodation();

        $this->get('/casa-frente-al-mar/book?arrival=2026-12-01&departure=2026-12-04&guests=2')
            ->assertSee('lang="es"', false)
            ->assertDontSee(url('strandhuis/book'), false);
    }

    public function test_links_on_the_site_use_the_address_of_the_language(): void
    {
        $this->accommodation();
        $this->accommodation(['slug' => 'second', 'name' => 'Tweede', 'name_translations' => null, 'slug_translations' => null]);

        $this->withSession(['locale' => 'es'])->get('/')
            ->assertOk()
            ->assertSee(url('casa-frente-al-mar'), false)
            ->assertDontSee(url('strandhuis'), false);
    }

    public function test_search_engines_get_the_addresses_per_language(): void
    {
        $this->accommodation();

        $this->withSession(['locale' => 'es'])->get('/casa-frente-al-mar')
            ->assertSee('<link rel="canonical" href="'.url('casa-frente-al-mar').'">', false)
            ->assertSee('hreflang="es" href="'.url('casa-frente-al-mar').'"', false)
            ->assertSee('hreflang="en" href="'.url('strandhuis').'"', false)
            ->assertSee('hreflang="nl" href="'.url('house-nl').'"', false)
            ->assertSee('hreflang="x-default" href="'.url('strandhuis').'"', false);
    }

    public function test_without_translated_addresses_no_language_references(): void
    {
        $this->accommodation(['slug_translations' => null, 'name_translations' => null]);

        $this->get('/strandhuis')->assertOk()->assertDontSee('hreflang', false);
    }

    public function test_a_switched_off_language_sends_through_to_the_default(): void
    {
        $this->accommodation();
        Language::query()->where('code', 'es')->update(['active' => false]);
        Language::forgets();

        $this->withHeaders(['Accept-Language' => 'de'])->get('/casa-frente-al-mar')
            ->assertRedirect(url('strandhuis'));
    }

    public function test_pages_have_also_a_address_per_language(): void
    {
        $this->page();

        $this->assertSame('sobre-nosotros', Page::firstWhere('slug', 'in-our')->slugFor('es'));

        $this->get('/sobre-nosotros')->assertOk()->assertSee('lang="es"', false)->assertSee('Sobre nosotros');
        $this->withSession(['locale' => 'es'])->get('/in-our')->assertRedirect(url('sobre-nosotros'));
    }

    public function test_a_unpublished_page_stays_hidden_on_every_address(): void
    {
        $this->page()->update(['published' => false]);

        $this->get('/sobre-nosotros')->assertNotFound();
        $this->get('/in-our')->assertNotFound();
    }

    public function test_a_address_is_made_off_the_translated_name(): void
    {
        $accommodation = $this->accommodation(['slug_translations' => null, 'name_translations' => ['de' => 'Haus am Strand']]);

        $this->assertSame('haus-am-strand', $accommodation->slugFor('de'));
    }

    public function test_a_existing_address_gets_a_number(): void
    {
        $this->accommodation();
        $second = $this->accommodation(['slug' => 'second', 'name_translations' => ['es' => 'Casa frente al mar'], 'slug_translations' => null]);

        $this->assertSame('casa-frente-al-mar-2', $second->slugFor('es'));
    }

    public function test_every_address_belongs_at_a_language(): void
    {
        $accommodation = $this->accommodation(['slug_translations' => ['es' => 'casa', 'fr' => 'casa', 'de' => 'strandhuis']]);

        $this->assertSame(['es' => 'casa'], $accommodation->slug_translations);
    }

    public function test_the_admin_rejects_a_address_that_already_in_usage_is(): void
    {
        $this->page();
        $accommodation = $this->accommodation(['slug_translations' => null, 'name_translations' => null]);
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        foreach (['sobre-nosotros', 'in-our', 'contact'] as $occupied) {
            Livewire::actingAs($admin)
                ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
                ->fillForm(['slug_translations' => ['es' => $occupied]])
                ->call('save')
                ->assertHasFormErrors(['slug_translations.es']);
        }

        Livewire::actingAs($admin)
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->fillForm(['slug_translations' => ['es' => 'Geen Geldig Adres!']])
            ->call('save')
            ->assertHasFormErrors(['slug_translations.es']);
    }

    public function test_the_admin_saves_a_own_address(): void
    {
        $accommodation = $this->accommodation(['slug_translations' => null, 'name_translations' => null]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->fillForm(['slug_translations' => ['es' => 'mi-casa']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('mi-casa', $accommodation->fresh()->slugFor('es'));
    }

    public function test_slug_search_finds_no_piece_of_a_address(): void
    {
        $this->accommodation();

        $this->assertNull(Property::findBySlug('casa'));
        $this->assertNull(Property::findBySlug('%'));
        $this->assertTrue(Slugs::occupied('admin'));
    }
}
