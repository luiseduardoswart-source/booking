<?php

namespace Tests\Feature;

use App\Engines\DocumentEngine;
use App\Enums\UserRole;
use App\Filament\Resources\Pages\Pages\ManagePages;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Livewire\AccommodationPhotos;
use App\Models\Booking;
use App\Models\Language;
use App\Models\Page;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeeplTranslator;
use App\Services\MachineTranslator;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * If a visitor chooses Spanish, everything is Spanish: the texts of the
 * accommodation, the labels on the photos, the error messages and the documents.
 */
class AccommodationMultilingualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @param array<string, mixed> $extra */
    private function accommodation(array $extra = []): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'NL', 'cancellation_policy' => 'moderate',
            'short_description' => 'Mooi huis aan zee',
            'description' => '<p>Een fijne plek</p>',
            'meta_title' => 'Strandhuis huren',
            'meta_description' => 'Boek dit strandhuis',
            ...$extra,
        ]);
    }

    /** @return array<string, array<string, string>> */
    private function spanish(): array
    {
        return [
            'name_translations' => ['es' => 'Casa de playa'],
            'short_description_translations' => ['es' => 'Bonita casa junto al mar'],
            'description_translations' => ['es' => '<p>Un lugar agradable</p>'],
            'meta_title_translations' => ['es' => 'Alquilar casa de playa'],
            'meta_description_translations' => ['es' => 'Reserva esta casa de playa'],
        ];
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function photo(Property $accommodation): mixed
    {
        Storage::fake('public');
        $img = imagecreatetruecolor(800, 600);
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $path, 85);
        imagedestroy($img);

        return $accommodation->addMedia($path)->toMediaCollection('photos');
    }

    private function booking(Property $accommodation, string $locale): Booking
    {
        return Booking::create([
            'booking_number' => 'BK-'.uniqid(), 'property_id' => $accommodation->id, 'locale' => $locale,
            'status' => 'confirmed', 'payment_status' => 'unpaid',
            'guest_name' => 'Ana', 'guest_email' => 'ana@example.com',
            'arrival_date' => '2026-12-01', 'departure_date' => '2026-12-03', 'guests' => 2,
            'total_cents' => 32000, 'down_payment_cents' => 9600, 'deposit_cents' => 5000,
            'price_snapshot' => ['lines' => []],
        ]);
    }

    public function test_the_detail_page_shows_everything_in_the_chosen_language(): void
    {
        $this->accommodation($this->spanish());

        $this->get('/es/casa-de-playa')
            ->assertOk()
            ->assertSee('Casa de playa')
            ->assertSee('Un lugar agradable', false)
            ->assertSee('<title>Alquilar casa de playa', false)
            ->assertSee('Reserva esta casa de playa')
            ->assertDontSee('Strandhuis huren')
            ->assertDontSee('Een fijne plek');
    }

    public function test_the_map_on_the_homepage_is_also_translated(): void
    {
        $this->accommodation($this->spanish());
        $this->accommodation(['slug' => 'second', 'name' => 'Tweede huis']);

        $this->get('/es')
            ->assertOk()
            ->assertSee('Casa de playa')
            ->assertDontSee('Strandhuis');
    }

    public function test_without_a_translation_the_dutch_text_stays(): void
    {
        $this->accommodation();

        $this->get('/es/strandhuis')->assertOk()->assertSee('Strandhuis');
    }

    public function test_the_admin_saves_the_translations(): void
    {
        $accommodation = $this->accommodation();

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->fillForm([
                'name_translations' => ['es' => 'Casa de playa'],
                'meta_title_translations' => ['es' => 'Alquilar casa de playa'],
                'highlights' => [[
                    'icon' => 'check-circle', 'title' => 'Aan zee', 'text' => null,
                    'title_translations' => ['es' => 'Junto al mar'],
                ]],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame('Casa de playa', $accommodation->name_translations['es']);
        $this->assertSame('Alquilar casa de playa', $accommodation->meta_title_translations['es']);

        app()->setLocale('es');
        $this->assertSame('Junto al mar', $accommodation->localizedHighlights()[0]['title']);
    }

    public function test_name_slug_and_description_are_per_language(): void
    {
        $accommodation = $this->accommodation();

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->assertSeeHtml('data.name_translations.es')
            ->assertSeeHtml('data.slug_translations.es')
            ->assertSeeHtml('data.short_description_translations.es')
            ->assertSeeHtml('data.description_translations.es')
            ->assertSeeHtml('data.meta_title_translations.es')
            ->assertDontSee('Adres per taal');
    }

    public function test_a_translated_name_fills_the_empty_slug_of_that_language(): void
    {
        $accommodation = $this->accommodation(['slug_translations' => ['de' => 'mein-haus']]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->set('data.name_translations.es', 'Casa de playa')
            ->assertSet('data.slug_translations.es', 'casa-de-playa')
            ->set('data.name_translations.de', 'Strandhaus')
            ->assertSet('data.slug_translations.de', 'mein-haus')
            ->assertSet('data.slug', 'strandhuis');
    }

    public function test_the_dutch_name_is_required_and_a_translated_one_is_not(): void
    {
        $accommodation = $this->accommodation();

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->fillForm(['name' => '', 'slug' => 'strandhuis'])
            ->call('save')
            ->assertHasFormErrors(['name' => 'required'])
            ->assertHasNoFormErrors(['name_translations.es', 'slug_translations.es']);
    }

    public function test_pages_also_have_all_texts_per_language(): void
    {
        Livewire::actingAs($this->owner())
            ->test(ManagePages::class)
            ->callAction('create', [
                'title' => 'Over ons',
                'slug' => 'in-our',
                'title_translations' => ['es' => 'Sobre nosotros'],
                'meta_title_translations' => ['es' => 'Quienes somos'],
            ])
            ->assertHasNoActionErrors();

        $page = Page::firstWhere('slug', 'in-our');
        $this->assertSame('Quienes somos', $page->meta_title_translations['es']);
        $this->assertSame('sobre-nosotros', $page->slugFor('es'));
    }

    public function test_saving_does_not_clear_the_texts_of_a_switched_off_language(): void
    {
        $accommodation = $this->accommodation([...$this->spanish(), 'house_rules' => 'Niet roken', 'house_rules_translations' => ['es' => 'No fumar']]);
        Language::query()->where('code', 'es')->update(['active' => false]);
        Language::forgets();

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame('Casa de playa', $accommodation->name_translations['es']);
        $this->assertSame('No fumar', $accommodation->house_rules_translations['es']);
    }

    public function test_the_translate_button_fills_only_the_empty_languages(): void
    {
        $this->app->instance(MachineTranslator::class, new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "[{$to}] {$text}";
            }
        });
        $accommodation = $this->accommodation(['name_translations' => ['es' => 'Casa de playa']]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->callAction(TestAction::make('translate_name_short_description_description')->schemaComponent('translations_name_short_description_description'))
            ->assertFormSet(fn (array $is) => $this->assertSame('Casa de playa', $is['name_translations']['es'])
                ?? $this->assertSame('[de] Strandhuis', $is['name_translations']['de'])
                ?? []);
    }

    public function test_labels_on_photos_are_per_language(): void
    {
        $accommodation = $this->accommodation();
        $photo = $this->photo($accommodation);

        Livewire::actingAs($this->owner())->test(AccommodationPhotos::class, ['property' => $accommodation])
            ->call('saveLabel', $photo->id, 'Zwembad')
            ->call('saveLabel', $photo->id, 'Piscina', 'es');

        $this->get('/es/strandhuis')->assertSee('Piscina')->assertDontSee('Zwembad');
        $this->get('/de/strandhuis')->assertSee('Zwembad');
    }

    public function test_an_empty_translated_label_is_removed(): void
    {
        $accommodation = $this->accommodation();
        $photo = $this->photo($accommodation);
        $photo->setCustomProperty('label_translations', ['es' => 'Piscina'])->save();

        Livewire::actingAs($this->owner())->test(AccommodationPhotos::class, ['property' => $accommodation])
            ->call('saveLabel', $photo->id, '  ', 'es');

        $this->assertSame([], $photo->fresh()->getCustomProperty('label_translations'));
    }

    public function test_a_label_in_an_unknown_language_is_rejected(): void
    {
        $accommodation = $this->accommodation();
        $photo = $this->photo($accommodation);

        Livewire::actingAs($this->owner())->test(AccommodationPhotos::class, ['property' => $accommodation])
            ->call('saveLabel', $photo->id, 'Stiekem', 'xx')
            ->assertNotFound();

        $this->assertNull($photo->fresh()->getCustomProperty('label_translations'));
    }

    public function test_the_translate_button_on_the_photos_fills_the_empty_labels(): void
    {
        $this->app->instance(MachineTranslator::class, new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "[{$to}] {$text}";
            }
        });
        $accommodation = $this->accommodation();
        $photo = $this->photo($accommodation);
        $photo->setCustomProperty('label', 'Zwembad')->setCustomProperty('label_translations', ['es' => 'Piscina'])->save();

        Livewire::actingAs($this->owner())->test(AccommodationPhotos::class, ['property' => $accommodation])
            ->call('translateLabels');

        $translations = $photo->fresh()->getCustomProperty('label_translations');
        $this->assertSame('Piscina', $translations['es']);
        $this->assertSame('[de] Zwembad', $translations['de']);
    }

    public function test_search_also_finds_the_translated_name(): void
    {
        $this->accommodation($this->spanish());
        $this->accommodation(['slug' => 'second', 'name' => 'Tweede huis']);

        $this->get('/es?where=Casa')
            ->assertOk()
            ->assertSee('Casa de playa')
            ->assertDontSee('Tweede huis');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function errorMessages(): array
    {
        return [
            'es' => ['es', 'El correo electrónico es obligatorio.'],
            'de' => ['de', 'E-Mail-Adresse ist ein Pflichtfeld.'],
            'fr' => ['fr', "L'adresse e-mail est obligatoire."],
            'zh' => ['zh', '电子邮箱 为必填项。'],
        ];
    }

    #[DataProvider('errorMessages')]
    public function test_error_messages_come_in_the_language_of_the_site(string $locale, string $notification): void
    {
        $this->withSession(['locale' => $locale])
            ->from('/contact')
            ->post('/contact', ['name' => 'Ana', 'message' => 'Hola'])
            ->assertSessionHasErrors(['email' => $notification]);
    }

    /** Which language was active at the moment the view was built. */
    private function documentLanguage(string $locale): string
    {
        $language = null;
        View::composer('pdf.confirmation', function () use (&$language) {
            $language = app()->getLocale();
        });

        app(DocumentEngine::class)->confirmation($this->booking($this->accommodation(), $locale));

        return $language;
    }

    public function test_a_document_comes_in_the_language_of_the_guest_also_when_made_from_the_admin(): void
    {
        app()->setLocale('nl');

        $this->assertSame('es', $this->documentLanguage('es'));
        $this->assertSame('nl', app()->getLocale());
    }

    public function test_a_language_without_a_font_gets_the_document_in_english(): void
    {
        $this->assertSame('en', $this->documentLanguage('zh'));
    }

    public function test_documents_no_longer_contain_fixed_dutch_text(): void
    {
        $accommodation = $this->accommodation($this->spanish());
        $booking = $this->booking($accommodation, 'es');
        $company = ['name' => 'Bedrijf', 'address' => null, 'postal_code_city' => null, 'email' => null, 'phone' => null, 'coc' => null, 'vat_number' => null, 'iban' => 'NL00'];

        app()->setLocale('es');
        $html = view('pdf.confirmation', ['booking' => $booking, 'company' => $company])->render();

        $this->assertStringContainsString('Casa de playa', $html);
        $this->assertStringContainsString('lang="es"', $html);

        foreach (['Boekingsnummer', 'Geboekt op', 'Prijsopbouw', 'gegenereerd op'] as $dutch) {
            $this->assertStringNotContainsString($dutch, $html);
        }
    }

    public function test_deepl_leaves_the_formatting_of_a_description_intact(): void
    {
        Setting::set('translations', 'deepl_api_key', 'test-key:fx');
        Http::fake(['*' => Http::response(['translations' => [['text' => 'x']]])]);

        (new DeeplTranslator)->translate('<p>Hallo</p>', 'nl', 'es');
        (new DeeplTranslator)->translate('Hallo', 'nl', 'es');

        $requests = Http::recorded();
        $this->assertSame('html', $requests[0][0]->data()['tag_handling'] ?? null);
        $this->assertArrayNotHasKey('tag_handling', $requests[1][0]->data());
    }
}
