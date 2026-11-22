<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Livewire\AccommodationPhotos;
use App\Models\Amenity;
use App\Models\Host;
use App\Models\Language;
use App\Models\Page;
use App\Models\Property;
use App\Models\User;
use App\Services\MachineTranslator;
use App\Support\Languages;
use App\Support\Translations;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * English is the source language: the main column is English, Dutch is an
 * ordinary translation that you can switch off.
 */
class EnglishAsSourceLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_01_170000_english_as_source_language.php');
    }

    private function accommodation(array $extra = []): Property
    {
        return Property::create([
            'name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'NL', 'cancellation_policy' => 'moderate',
            ...$extra,
        ]);
    }

    /** A row as it looked before this change: Dutch in the main column. */
    private function oldAccommodation(array $extra = []): int
    {
        return DB::table('properties')->insertGetId([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'is_master' => false, 'created_at' => now(), 'updated_at' => now(),
            ...$extra,
        ]);
    }

    private function fakeTranslator(): void
    {
        $this->app->instance(MachineTranslator::class, new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "[{$from}>{$to}] {$text}";
            }
        });
    }

    public function test_the_english_text_is_the_main_text_and_the_dutch_goes_not_lost(): void
    {
        $id = $this->oldAccommodation([
            'name_translations' => json_encode(['en' => 'Beach house', 'es' => 'Casa']),
            'slug_translations' => json_encode(['en' => 'beach-house', 'es' => 'casa']),
        ]);

        $this->migration()->up();

        $row = DB::table('properties')->find($id);
        $this->assertSame('Beach house', $row->name);
        $this->assertSame(['es' => 'Casa', 'nl' => 'Strandhuis'], json_decode($row->name_translations, true));
        $this->assertSame('beach-house', $row->slug);
        $this->assertSame(['es' => 'casa', 'nl' => 'strandhuis'], json_decode($row->slug_translations, true));
    }

    public function test_without_english_text_stays_the_main_text_are_and_comes_he_also_at_dutch(): void
    {
        $id = $this->oldAccommodation(['short_description' => 'Mooi huis']);

        $this->migration()->up();

        $row = DB::table('properties')->find($id);
        $this->assertSame('Strandhuis', $row->name);
        $this->assertSame('strandhuis', $row->slug);
        $this->assertSame('Mooi huis', $row->short_description);
        $this->assertSame(['nl' => 'Mooi huis'], json_decode($row->short_description_translations, true));
    }

    public function test_the_old_address_stays_work_after_the_conversion(): void
    {
        $this->oldAccommodation(['slug_translations' => json_encode(['en' => 'beach-house'])]);

        $this->migration()->up();

        $this->followingRedirects()->withSession(['locale' => 'nl'])->get('/strandhuis')->assertOk()->assertSee('lang="nl"', false);
        $this->followingRedirects()->withSession(['locale' => 'en'])->get('/beach-house')->assertOk();
    }

    public function test_highlights_page_host_and_photo_label_go_along(): void
    {
        $accommodation = $this->oldAccommodation(['highlights' => json_encode([[
            'icon' => 'check-circle', 'title' => 'Aan zee', 'text' => null, 'title_translations' => ['en' => 'By the sea'],
        ]])]);
        $page = DB::table('pages')->insertGetId([
            'slug' => 'in-our', 'title' => 'Over ons', 'title_translations' => json_encode(['en' => 'About us']),
            'published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $host = Host::create(['name' => 'Ana', 'bio' => 'Hallo', 'bio_translations' => ['en' => 'Hello']]);
        Storage::fake('public');
        $img = imagecreatetruecolor(40, 40);
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $path);
        $photo = Property::find($accommodation)->addMedia($path)->toMediaCollection('photos');
        $photo->setCustomProperty('label', 'Zwembad')->setCustomProperty('label_translations', ['en' => 'Pool'])->save();

        $this->migration()->up();

        $row = json_decode(DB::table('properties')->find($accommodation)->highlights, true)[0];
        $this->assertSame('By the sea', $row['title']);
        $this->assertSame('Aan zee', $row['title_translations']['nl']);
        $this->assertSame('About us', DB::table('pages')->find($page)->title);
        $this->assertSame('Hello', $host->fresh()->bio);
        $this->assertSame('Hallo', $host->fresh()->bio_translations['nl']);
        $this->assertSame('Pool', $photo->fresh()->getCustomProperty('label'));
        $this->assertSame('Zwembad', $photo->fresh()->getCustomProperty('label_translations')['nl']);
    }

    public function test_revert_set_the_dutch_back(): void
    {
        $id = $this->oldAccommodation(['name_translations' => json_encode(['en' => 'Beach house'])]);

        $this->migration()->up();
        $this->migration()->down();

        $row = DB::table('properties')->find($id);
        $this->assertSame('Strandhuis', $row->name);
        $this->assertSame(['en' => 'Beach house'], json_decode($row->name_translations, true));
    }

    public function test_the_first_tab_is_english_and_dutch_is_a_regular_language(): void
    {
        $accommodation = $this->accommodation();
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->assertSeeHtml('data.name_translations.nl')
            ->assertDontSeeHtml('data.name_translations.en');
    }

    public function test_dutch_switch_off_fetches_the_tab_gone_and_saves_the_text(): void
    {
        $accommodation = $this->accommodation(['name_translations' => ['nl' => 'Strandhuis']]);
        Language::query()->where('code', 'nl')->update(['active' => false, 'is_default' => false]);
        Language::query()->where('code', 'en')->update(['is_default' => true]);
        Language::forgets();

        $page = Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()]);

        // No "Nederlands" tab any more (the word itself may appear elsewhere, such as in
        // the currency list at "Nederlands-Antilliaanse gulden").
        $this->assertMatchesRegularExpression('/fi-tabs-item-label">\s*English\s*</', $page->html());
        $this->assertDoesNotMatchRegularExpression('/fi-tabs-item-label">\s*Dutch\s*</', $page->html());

        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame('Strandhuis', $accommodation->fresh()->name_translations['nl']);
    }

    public function test_the_translate_button_translates_from_the_english(): void
    {
        $this->fakeTranslator();
        $accommodation = $this->accommodation();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->callAction(TestAction::make('translate_name_short_description_description')->schemaComponent('translations_name_short_description_description'))
            ->assertSet('data.name_translations.nl', '[en>nl] Beach house')
            ->assertSet('data.name_translations.es', '[en>es] Beach house');
    }

    public function test_photo_labels_translate_from_the_english(): void
    {
        $this->fakeTranslator();
        Storage::fake('public');
        $accommodation = $this->accommodation();
        $img = imagecreatetruecolor(40, 40);
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $path);
        $photo = $accommodation->addMedia($path)->toMediaCollection('photos');
        $photo->setCustomProperty('label', 'Pool')->save();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(AccommodationPhotos::class, ['property' => $accommodation])
            ->call('translateLabels');

        $this->assertSame('[en>nl] Pool', $photo->fresh()->getCustomProperty('label_translations')['nl']);
    }

    public function test_a_page_translates_also_from_the_english(): void
    {
        $this->fakeTranslator();
        $page = Page::create(['title' => 'About us', 'slug' => 'about']);

        Translations::fillEmptyLanguages([$page], ['title']);

        $this->assertSame('[en>nl] About us', $page->fresh()->title_translations['nl']);
    }

    public function test_amenities_translate_also_from_the_english(): void
    {
        $this->fakeTranslator();
        $custom = Amenity::create(['name' => 'Own outdoor kitchen']);

        Translations::fillEmptyLanguages([$custom], ['name']);

        $this->assertSame('[en>nl] Own outdoor kitchen', $custom->fresh()->name_translations['nl']);
        $this->assertArrayNotHasKey('en', $custom->fresh()->name_translations);
    }

    public function test_the_source_language_is_on_a_spot(): void
    {
        $this->assertSame('en', Languages::SOURCE);
        $this->assertSame('en', $this->accommodation()->sourceLanguage());
        $this->assertSame('en', (new Amenity)->sourceLanguage());
        $this->assertSame('en', (new \App\Models\PropertyType)->sourceLanguage());
    }
}
