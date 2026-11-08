<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Property;
use App\Services\Importing\FakeListingImporter;
use App\Services\Importing\ListingDraft;
use App\Services\Importing\ListingImportException;
use App\Services\Importing\ListingImportProvider;
use App\Services\Importing\ListingImportService;
use App\Services\Importing\MetadataImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListingImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_importer_reads_open_graph_and_json_ld(): void
    {
        $html = <<<'HTML'
        <html><head>
            <meta property="og:title" content="Prachtig strandhuis">
            <meta property="og:description" content="Direct aan zee met groot terras">
            <meta property="og:image" content="https://cdn.example.com/foto1.jpg">
            <meta property="og:image" content="https://cdn.example.com/foto2.jpg">
            <script type="application/ld+json">
            {"@type":"LodgingBusiness","name":"Prachtig strandhuis","description":"Volledige beschrijving hier",
             "address":{"addressLocality":"Zandvoort","addressCountry":"NL"},
             "geo":{"latitude":52.37,"longitude":4.53}}
            </script>
        </head><body>...</body></html>
        HTML;

        Http::fake(['*' => Http::response($html, 200)]);

        $draft = app(MetadataImporter::class)->import('https://example.test/listing/1');

        $this->assertSame('Prachtig strandhuis', $draft->name);
        $this->assertSame('Direct aan zee met groot terras', $draft->shortDescription);
        $this->assertSame('Volledige beschrijving hier', $draft->description);
        $this->assertSame('Zandvoort', $draft->city);
        $this->assertSame('NL', $draft->country);
        $this->assertEqualsWithDelta(52.37, $draft->latitude, 0.001);
        $this->assertCount(2, $draft->photoUrls);
    }

    public function test_airbnb_puts_the_own_title_in_the_name_and_not_in_the_short_description(): void
    {
        // Airbnb fills og:title with a facts line and og:description with the
        // title the host wrote themselves — so the other way round.
        $html = <<<'HTML'
        <html><head>
            <meta property="og:title" content="Appartement · Cartagena · ★4,85 · 1 slaapkamer · 2 bedden · 2 badkamers">
            <meta property="og:description" content="Kingsize bed · Uitzicht op de oceaan · Zwembad op het dak · Bocagrande">
            <meta property="og:image" content="https://cdn.airbnb.com/foto1.jpg">
        </head><body>...</body></html>
        HTML;

        Http::fake(['*' => Http::response($html, 200)]);

        $draft = app(MetadataImporter::class)->import('https://www.airbnb.nl/rooms/12345');

        $this->assertSame('Kingsize bed · Uitzicht op de oceaan · Zwembad op het dak · Bocagrande', $draft->name);
        $this->assertNull($draft->shortDescription);
    }

    public function test_airbnb_fetches_the_counts_off_the_fact_line(): void
    {
        $html = <<<'HTML'
        <html><head>
            <meta property="og:title" content="Appartement · Cartagena · ★4,85 · 1 slaapkamer · 2 bedden · 2 badkamers">
            <meta property="og:description" content="Mijn eigen titel">
            <meta property="og:image" content="https://cdn.airbnb.com/foto1.jpg">
        </head><body>...</body></html>
        HTML;

        Http::fake(['*' => Http::response($html, 200)]);

        $draft = app(MetadataImporter::class)->import('https://www.airbnb.nl/rooms/12345');

        $this->assertSame(1, $draft->bedrooms);
        $this->assertSame(2, $draft->beds);
        $this->assertSame(2, $draft->bathrooms);
    }

    public function test_airbnb_fact_line_works_also_in_the_english(): void
    {
        $html = <<<'HTML'
        <html><head>
            <meta property="og:title" content="Apartment in Cartagena · ★4.85 · 2 bedrooms · 3 beds · 1 bath">
            <meta property="og:description" content="Rooftop pool with ocean view">
            <meta property="og:image" content="https://cdn.airbnb.com/foto1.jpg">
        </head><body>...</body></html>
        HTML;

        Http::fake(['*' => Http::response($html, 200)]);

        $draft = app(MetadataImporter::class)->import('https://www.airbnb.com/rooms/12345');

        $this->assertSame('Rooftop pool with ocean view', $draft->name);
        $this->assertSame(2, $draft->bedrooms);
        $this->assertSame(3, $draft->beds);
        $this->assertSame(1, $draft->bathrooms);
    }

    public function test_import_asks_the_page_on_in_the_default_language_of_the_site(): void
    {
        Language::query()->update(['is_default' => false]);
        Language::query()->where('code', 'en')->update(['is_default' => true, 'active' => true]);
        Language::forgets();

        Http::fake(['*' => Http::response('<html><head><meta property="og:title" content="Iets"></head></html>', 200)]);

        app(MetadataImporter::class)->import('https://www.airbnb.com/rooms/999?locale=nl');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'locale=en')
                && ! str_contains($request->url(), 'locale=nl')
                && str_starts_with($request->header('Accept-Language')[0], 'en');
        });
    }

    public function test_metadata_importer_fails_gracefully_without_usable_data(): void
    {
        Http::fake(['*' => Http::response('<html><head></head><body>niets</body></html>', 200)]);

        $this->expectException(ListingImportException::class);
        app(MetadataImporter::class)->import('https://example.test/empty');
    }

    public function test_service_makes_draft_accommodation_of_draft(): void
    {
        $draft = new ListingDraft(
            name: 'Boshuisje',
            description: 'Rustig gelegen',
            city: 'Otterlo',
            maxGuests: 5,
            bedrooms: 2,
            basePriceCents: 14500,
        );

        $property = app(ListingImportService::class)->createFromDraft($draft);

        $this->assertDatabaseHas('properties', [
            'name' => 'Boshuisje', 'city' => 'Otterlo', 'max_guests' => 5,
            'bedrooms' => 2, 'base_price_cents' => 14500, 'active' => false,
        ]);
        $this->assertSame('boshuisje', $property->slug);
    }

    public function test_import_action_via_provider_gives_property(): void
    {
        $fake = new FakeListingImporter;
        $fake->draft = new ListingDraft(name: 'Via link', city: 'Delft', maxGuests: 3);
        $this->app->instance(ListingImportProvider::class, $fake);

        $property = app(ListingImportService::class)->importFromUrl('https://example.test/x');

        $this->assertInstanceOf(Property::class, $property);
        $this->assertSame('Via link', $property->name);
        $this->assertFalse((bool) $property->active);
    }

    public function test_double_name_gets_unique_slug(): void
    {
        Property::create(['name' => 'Duinhuis', 'slug' => 'duinhuis', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true]);

        $property = app(ListingImportService::class)->createFromDraft(new ListingDraft(name: 'Duinhuis'));

        $this->assertSame('duinhuis-2', $property->slug);
    }
}
