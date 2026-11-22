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
 * Every language its own address: how slugs per language are made and checked.
 * How the addresses are used on the site (/es/casa-frente-al-mar): LanguageAddressesTest.
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
