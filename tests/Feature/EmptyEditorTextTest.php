<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Page;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An emptied text editor stores "<p></p>". That is no text: a visitor in that
 * language gets the English text instead of an empty description, and saving
 * an emptied editor stores nothing.
 */
class EmptyEditorTextTest extends TestCase
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
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'description' => '<p>A house by the sea.</p>', ...$extra,
        ]);
    }

    public function test_an_empty_translation_shows_the_english_text(): void
    {
        $accommodation = $this->accommodation(['description_translations' => ['nl' => '<p></p>', 'de' => '<p> &nbsp;</p>', 'es' => '<p>Una casa junto al mar.</p>']]);

        $this->assertSame('<p>A house by the sea.</p>', $accommodation->localized('description', 'nl'));
        $this->assertSame('<p>A house by the sea.</p>', $accommodation->localized('description', 'de'));
        $this->assertSame('<p>Una casa junto al mar.</p>', $accommodation->localized('description', 'es'));

        $this->withSession(['locale' => 'nl'])->get(route('properties.show', $accommodation->slug))
            ->assertOk()
            ->assertSee('A house by the sea.');
    }

    public function test_an_empty_page_translation_shows_the_english_text(): void
    {
        $page = Page::create(['title' => 'About', 'slug' => 'about', 'content' => '<p>About us</p>', 'content_translations' => ['nl' => '<p></p>'], 'published' => true]);

        $this->assertSame('<p>About us</p>', $page->localized('content', 'nl'));
    }

    public function test_an_emptied_editor_is_saved_as_empty(): void
    {
        $accommodation = $this->accommodation(['description_translations' => ['nl' => '<p>Een huis aan zee.</p>']]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->set('data.description_translations.nl', '<p></p>')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($accommodation->refresh()->description_translations['nl'] ?? null);
        $this->assertSame('<p>A house by the sea.</p>', $accommodation->description);
    }
}
