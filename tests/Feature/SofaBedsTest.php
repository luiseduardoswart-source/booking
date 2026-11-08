<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use App\Services\Importing\MetadataImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The number of sofa beds, next to bedrooms, beds and bathrooms: in the
 * admin, on the site, in the API and when importing a listing.
 */
class SofaBedsTest extends TestCase
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
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
            'beds' => 2, ...$extra,
        ]);
    }

    public function test_the_count_is_in_the_admin_saved(): void
    {
        $accommodation = $this->accommodation();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->assertSeeHtml('data.sofa_beds')
            ->fillForm(['sofa_beds' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $accommodation->fresh()->sofa_beds);
    }

    public function test_the_site_shows_the_sofa_beds_in_single_and_plural(): void
    {
        $accommodation = $this->accommodation(['sofa_beds' => 1]);

        $this->withSession(['locale' => 'nl'])->get("/{$accommodation->slug}")
            ->assertOk()->assertSee('· 1 slaapbank', false)->assertDontSee('sofa_beds');

        $accommodation->update(['sofa_beds' => 2]);
        $this->withSession(['locale' => 'nl'])->get("/{$accommodation->slug}")
            ->assertOk()->assertSee('· 2 slaapbanken', false);

        $accommodation->update(['sofa_beds' => null]);
        $this->withSession(['locale' => 'nl'])->get("/{$accommodation->slug}")
            ->assertOk()->assertDontSee('sofa_bed');
    }

    public function test_the_api_gives_the_count_along(): void
    {
        $accommodation = $this->accommodation(['sofa_beds' => 2]);

        $this->getJson("/api/v1/properties/{$accommodation->id}")
            ->assertOk()
            ->assertJsonPath('data.sofa_beds', 2)
            ->assertJsonPath('data.beds', 2);
    }

    public function test_the_import_counts_a_sofa_bed_not_as_bed(): void
    {
        $listings = [
            'https://www.airbnb.nl/rooms/1' => 'Appartement · Cartagena · 1 slaapkamer · 2 bedden · 1 slaapbank · 1 badkamer',
            'https://www.airbnb.com/rooms/2' => 'Apartment in Cartagena · 1 bedroom · 1 sofa bed · 2 beds · 1 bath',
        ];
        Http::preventStrayRequests();
        Http::fake(collect($listings)->mapWithKeys(fn (string $facts, string $url) => [
            $url.'*' => Http::response('<html><head><meta property="og:title" content="'.$facts.'"><meta property="og:description" content="Eigen titel"></head></html>', 200),
        ])->all());

        foreach ($listings as $url => $facts) {
            $draft = app(MetadataImporter::class)->import($url);

            $this->assertSame(2, $draft->beds, $facts);
            $this->assertSame(1, $draft->sofaBeds, $facts);
            $this->assertSame(1, $draft->bedrooms, $facts);
            $this->assertSame(['sofa_beds' => 1], array_intersect_key($draft->toAttributes(), ['sofa_beds' => 0]));
        }
    }
}
