<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Hosts\Pages\ManageHosts;
use App\Filament\Resources\Pages\Pages\ManagePages;
use App\Models\Amenity;
use App\Models\Host;
use App\Models\Page;
use App\Models\User;
use App\Services\MachineTranslator;
use App\Support\Translations;
use ArrayObject;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shared translation module: HasTranslations (model), Translations (filling
 * in) and TranslationFields (form and buttons).
 */
class TranslateModuleTest extends TestCase
{
    use RefreshDatabase;

    /** Fake translation service that keeps track of which languages were requested. */
    private function fakeTranslator(): ArrayObject
    {
        $asked = new ArrayObject;
        $this->app->bind(MachineTranslator::class, fn () => new class($asked) implements MachineTranslator
        {
            public function __construct(private ArrayObject $asked) {}

            public function translate(string $text, string $from, string $to): string
            {
                $this->asked[] = $to;

                return "{$text} [{$to}]";
            }
        });

        return $asked;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_localized_chooses_own_translation_then_source_text(): void
    {
        $host = Host::create(['name' => 'Marieke', 'bio' => 'Hallo', 'bio_translations' => ['en' => 'Hello']]);

        $this->assertSame('Hello', $host->localized('bio', 'en'));
        $this->assertSame('Hallo', $host->localized('bio', 'de'));
    }

    public function test_localized_used_language_file_only_where_the_model_that_allows(): void
    {
        $washingMachine = Amenity::firstWhere('name', 'Washing machine');

        // Amenity names are in the language files...
        $this->assertSame('Washing machine', $washingMachine->localized('name', 'en'));
        $this->assertSame('Wasmachine', $washingMachine->localized('name', 'nl'));

        // ...a host bio is not: it falls back on the source text, even if that
        // happens to equal a key in the language files.
        $host = Host::create(['name' => 'X', 'bio' => 'Washing machine']);
        $this->assertSame('Washing machine', $host->localized('bio', 'nl'));
    }

    public function test_fill_in_fills_only_what_empty_is(): void
    {
        $asked = $this->fakeTranslator();
        $count = 0;

        $result = Translations::fillIn('Hello', ['nl' => 'Hallo'], null, $count);

        $this->assertSame('Hallo', $result['nl']);
        $this->assertSame('Hello [de]', $result['de']);
        $this->assertArrayNotHasKey('en', $result);
        $this->assertNotContains('nl', $asked->getArrayCopy());
        $this->assertSame(count($asked), $count);
    }

    public function test_fill_in_does_nothing_with_a_empty_source_text(): void
    {
        $asked = $this->fakeTranslator();

        $this->assertSame([], Translations::fillIn('   ', null));
        $this->assertCount(0, $asked);
    }

    public function test_fill_in_saves_built_in_translations_in(): void
    {
        $asked = $this->fakeTranslator();

        Translations::fillIn('Wasmachine', [], fn (string $locale) => $locale === 'de');

        $this->assertNotContains('de', $asked->getArrayCopy());
        $this->assertContains('fr', $asked->getArrayCopy());
    }

    public function test_a_record_without_something_to_translate_is_not_saved(): void
    {
        $this->fakeTranslator();
        $page = Page::create(['title' => 'Over', 'slug' => 'over', 'published' => true]);
        $translated = collect(['nl', 'de', 'fr', 'es', 'zh'])->mapWithKeys(fn ($l) => [$l => "Over {$l}"])->all();
        $page->forceFill(['title_translations' => $translated])->saveQuietly();
        $before = $page->fresh()->updated_at;

        $this->travel(5)->minutes();
        Translations::fillEmptyLanguages([$page->fresh()], ['title']);

        $this->assertEquals($before, $page->fresh()->updated_at);
    }

    public function test_translate_button_at_pages_fills_title_and_meta_but_not_the_content(): void
    {
        $this->fakeTranslator();
        $page = Page::create([
            'title' => 'Over ons', 'slug' => 'in-our', 'published' => true,
            'meta_title' => 'Over ons | Huis', 'content' => '<p>Tekst</p>',
        ]);

        Livewire::actingAs($this->admin())
            ->test(ManagePages::class)
            ->callAction(TestAction::make('translate')->table());

        $page->refresh();
        $this->assertSame('Over ons [nl]', $page->title_translations['nl']);
        $this->assertSame('Over ons | Huis [nl]', $page->meta_title_translations['nl']);
        // Formatted text is deliberately not machine-translated.
        $this->assertEmpty($page->content_translations['nl'] ?? null);
    }

    public function test_translation_fields_of_a_page_are_via_the_form_saved(): void
    {
        $page = Page::create(['title' => 'Over', 'slug' => 'over', 'published' => true]);

        Livewire::actingAs($this->admin())
            ->test(ManagePages::class)
            ->callAction(TestAction::make('edit')->table($page), data: [
                'title_translations' => ['nl' => 'Over vertaald'],
                'content_translations' => ['nl' => '<p>Tekst</p>'],
            ])
            ->assertHasNoFormErrors();

        $page->refresh();
        $this->assertSame('Over vertaald', $page->title_translations['nl']);
        $this->assertStringContainsString('Tekst', $page->content_translations['nl']);
    }

    public function test_translation_field_of_a_host_is_via_the_form_saved(): void
    {
        $host = Host::create(['name' => 'Marieke', 'bio' => 'Hallo']);

        Livewire::actingAs($this->admin())
            ->test(ManageHosts::class)
            ->callAction(TestAction::make('edit')->table($host), data: [
                'bio_translations' => ['de' => 'Hallo auf Deutsch'],
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('Hallo auf Deutsch', $host->fresh()->bio_translations['de']);
    }
}
