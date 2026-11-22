<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Page;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What search engines and social media read: the sitemap, robots.txt, which
 * pages stay out, the tags in the head and the structured data.
 */
class SeoTest extends TestCase
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
        Storage::fake('public');
        Setting::set('company', 'name', 'Devanii Apartments');
    }

    private function accommodation(array $extra = []): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'apartment', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'city' => 'Cartagena', 'country' => 'CO',
            'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 1,
            'short_description' => 'Sea view apartment',
            'name_translations' => ['es' => 'Casa frente al mar'],
            'slug_translations' => ['es' => 'casa-frente-al-mar'],
            ...$extra,
        ]);
    }

    /** The JSON-LD blocks of a page, decoded. */
    private function structuredData(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);

        return array_map(fn (string $json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $blocks[1]);
    }

    private function ofType(array $blocks, string $type): ?array
    {
        return collect($blocks)->first(fn (array $block) => ($block['@type'] ?? null) === $type);
    }

    public function test_the_sitemap_lists_every_public_page_in_every_language(): void
    {
        $this->accommodation();
        $this->accommodation(['slug' => 'hidden', 'name' => 'Hidden', 'active' => false, 'slug_translations' => null, 'name_translations' => null]);
        Page::create(['title' => 'About us', 'slug' => 'about-us', 'published' => true]);
        Page::create(['title' => 'Draft', 'slug' => 'draft', 'published' => false]);

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

        foreach (['/', 'es', 'contact', 'es/contact', 'strandhuis', 'es/casa-frente-al-mar', 'nl/strandhuis', 'about-us', 'es/about-us'] as $path) {
            $this->assertStringContainsString('<loc>'.url($path).'</loc>', $xml, $path);
        }

        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="es" href="'.url('es/casa-frente-al-mar').'"/>', $xml);
        $this->assertStringNotContainsString('hidden', $xml);
        $this->assertStringNotContainsString('draft', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_robots_txt_points_to_the_sitemap_and_keeps_the_admin_out(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Sitemap: '.url('sitemap.xml'), false)
            ->assertSee('Disallow: /admin', false);
    }

    public function test_private_pages_stay_out_of_search_engines(): void
    {
        $property = $this->accommodation();

        $this->get('/strandhuis/book?arrival=2027-03-01&departure=2027-03-04&guests=2')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/my-bookings')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $booking = app(\App\Engines\BookingEngine::class)->create($property, \Carbon\CarbonImmutable::parse('2027-03-01'), \Carbon\CarbonImmutable::parse('2027-03-04'), 2, ['name' => 'Jan', 'email' => 'jan@example.com']);
        $this->get(URL::signedRoute('bookings.confirmation', $booking))->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // The public pages are indexed, with large image previews allowed.
        $this->get('/strandhuis')->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large">', false);
    }

    public function test_an_accommodation_has_the_tags_for_sharing(): void
    {
        $property = $this->accommodation();
        $photo = $property->addPhoto(UploadedFile::fake()->image('front.jpg', 1200, 800));
        $image = $property->fresh()->coverPhotoUrl('large');

        $this->get('/es/casa-frente-al-mar')->assertOk()
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('<meta property="og:url" content="'.url('es/casa-frente-al-mar').'">', false)
            ->assertSee('<meta property="og:site_name" content="Devanii Apartments">', false)
            ->assertSee('<meta property="og:locale" content="es_ES">', false)
            ->assertSee('<meta property="og:locale:alternate" content="en_US">', false)
            ->assertSee('<meta property="og:image" content="'.$image.'">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta name="twitter:image" content="'.$image.'">', false)
            ->assertSee('<meta name="theme-color"', false);

        $this->assertNotNull($photo);
    }

    public function test_an_accommodation_is_described_as_a_vacation_rental(): void
    {
        $property = $this->accommodation(['latitude' => 10.4236541, 'longitude' => -75.5512345, 'location_display' => Property::LOCATION_EXACT, 'address' => 'Calle 1', 'check_in_from' => '15:00', 'check_out_until' => '11:00']);
        $property->addPhoto(UploadedFile::fake()->image('front.jpg', 1200, 800));

        $blocks = $this->structuredData($this->get('/strandhuis')->assertOk()->getContent());
        $rental = $this->ofType($blocks, 'VacationRental');

        $this->assertSame('Strandhuis', $rental['name']);
        $this->assertSame((string) $property->id, $rental['identifier']);
        $this->assertSame('Apartment', $rental['additionalType']);
        $this->assertSame(4, $rental['containsPlace']['occupancy']['value']);
        $this->assertSame('EntirePlace', $rental['containsPlace']['additionalType']);
        $this->assertSame(2, $rental['containsPlace']['numberOfBedrooms']);
        $this->assertSame(10.42365, $rental['latitude']);
        $this->assertSame('Calle 1', $rental['address']['streetAddress']);
        $this->assertSame('15:00:00', $rental['checkinTime']);
        $this->assertCount(1, $rental['image']);

        $crumbs = $this->ofType($blocks, 'BreadcrumbList');
        $this->assertSame(url('/'), $crumbs['itemListElement'][0]['item']);
        $this->assertSame('Strandhuis', $crumbs['itemListElement'][1]['name']);
    }

    public function test_a_hidden_location_stays_out_of_the_structured_data(): void
    {
        $this->accommodation(['latitude' => 10.4236541, 'longitude' => -75.5512345, 'location_display' => Property::LOCATION_APPROXIMATE, 'address' => 'Calle 1', 'postal_code' => '130001']);

        $rental = $this->ofType($this->structuredData($this->get('/strandhuis')->getContent()), 'VacationRental');

        $this->assertArrayNotHasKey('latitude', $rental);
        $this->assertArrayNotHasKey('streetAddress', $rental['address']);
        $this->assertArrayNotHasKey('postalCode', $rental['address']);
        $this->assertSame('Cartagena', $rental['address']['addressLocality']);
    }

    public function test_text_from_the_admin_cannot_break_out_of_the_structured_data(): void
    {
        $this->accommodation(['name' => 'Huis </script><script>alert(1)</script>']);

        $html = $this->get('/strandhuis')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertSame('Huis </script><script>alert(1)</script>', $this->ofType($this->structuredData($html), 'VacationRental')['name']);
    }

    public function test_the_home_page_has_its_own_title_description_and_the_company(): void
    {
        $this->accommodation();
        $this->accommodation(['slug' => 'second', 'name' => 'Second', 'slug_translations' => null, 'name_translations' => null]);
        Setting::set('seo', 'home_title_en', 'Apartments in Cartagena by the sea');
        Setting::set('seo', 'home_description_es', 'Apartamentos en Cartagena');
        Property::master()->update(['social_links' => ['instagram' => 'https://instagram.com/devanii']]);

        $html = $this->get('/')->assertOk()
            ->assertSee('<title>Apartments in Cartagena by the sea — Devanii Apartments</title>', false)
            ->getContent();
        $this->get('/es')->assertSee('<meta name="description" content="Apartamentos en Cartagena">', false);

        $blocks = $this->structuredData($html);
        $this->assertSame('Devanii Apartments', $this->ofType($blocks, 'Organization')['name']);
        $this->assertSame(['https://instagram.com/devanii'], $this->ofType($blocks, 'Organization')['sameAs']);
        $this->assertSame(url('/'), $this->ofType($blocks, 'WebSite')['url']);
    }

    public function test_the_owner_sets_the_search_engine_texts_in_the_admin(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(Settings::class)
            ->fillForm(['seo_home_title_en' => 'Apartments in Cartagena', 'seo_home_description_en' => 'Book directly'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Apartments in Cartagena', Setting::get('seo', 'home_title_en'));
        $this->assertSame('Book directly', Setting::get('seo', 'home_description_en'));
    }

    public function test_photos_lower_on_the_page_load_when_they_come_into_view(): void
    {
        foreach (['a', 'b'] as $slug) {
            $this->accommodation(['slug' => $slug, 'name' => strtoupper($slug), 'slug_translations' => null, 'name_translations' => null])
                ->addPhoto(UploadedFile::fake()->image("{$slug}.jpg", 800, 600));
        }

        $this->get('/')->assertSee('loading="lazy"', false);
        $this->get('/a')->assertSee('fetchpriority="high"', false);
    }
}
