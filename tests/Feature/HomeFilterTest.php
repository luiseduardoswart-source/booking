<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Property;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeFilterTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccommodations(): void
    {
        Property::create([
            'name' => 'Strandvilla', 'slug' => 'strandvilla', 'type' => 'holiday_home',
            'max_guests' => 6, 'bedrooms' => 4, 'bathrooms' => 3, 'active' => true,
            'base_price_cents' => 30000, 'latitude' => 52.1, 'longitude' => 4.3,
        ]);

        Property::create([
            'name' => 'Stadsappartement', 'slug' => 'stadsappartement', 'type' => 'apartment',
            'max_guests' => 2, 'bedrooms' => 1, 'bathrooms' => 1, 'active' => true,
            'base_price_cents' => 9000, 'latitude' => 52.4, 'longitude' => 4.9,
        ]);
    }

    public function test_filter_bar_and_map_appear_at_multiple_accommodations(): void
    {
        $this->makeAccommodations();

        $this->withSession(['locale' => 'nl'])
            ->get('/')
            ->assertOk()
            ->assertSee(__('Price per night: all'))
            ->assertSee(__('Explore the map'))
            ->assertSee('explore-map-data', false)
            ->assertSee('Strandvilla');
    }

    public function test_type_filter_restricts_the_results(): void
    {
        $this->makeAccommodations();

        $this->withSession(['locale' => 'nl'])
            ->get('/?type=apartment')
            ->assertOk()
            ->assertSee('Stadsappartement')
            ->assertDontSee('Strandvilla');
    }

    public function test_price_filter_used_the_effective_price(): void
    {
        $this->makeAccommodations();

        $this->withSession(['locale' => 'nl'])
            ->get('/?price_max=150')
            ->assertOk()
            ->assertSee('Stadsappartement')
            ->assertDontSee('Strandvilla');
    }

    public function test_bedroom_and_bathroom_filter(): void
    {
        $this->makeAccommodations();

        $this->withSession(['locale' => 'nl'])
            ->get('/?bedrooms=3&bathrooms=2')
            ->assertOk()
            ->assertSee('Strandvilla')
            ->assertDontSee('Stadsappartement');
    }

    public function test_amenities_filter(): void
    {
        $this->makeAccommodations();
        $swimmingPool = Amenity::firstWhere('name', 'Swimming pool');
        Property::query()->where('slug', 'strandvilla')->first()->amenities()->attach($swimmingPool);

        $this->withSession(['locale' => 'nl'])
            ->get('/?amenity='.$swimmingPool->id)
            ->assertOk()
            ->assertSee('Strandvilla')
            ->assertDontSee('Stadsappartement');
    }

    public function test_location_field_searches_on_name_and_city(): void
    {
        $this->makeAccommodations();
        Property::query()->where('slug', 'strandvilla')->first()->update(['city' => 'Zandvoort']);

        // Op plaats.
        $this->withSession(['locale' => 'nl'])
            ->get('/?where=zandvoort')
            ->assertOk()
            ->assertSee('Strandvilla')
            ->assertDontSee('Stadsappartement');

        // Op naam.
        $this->withSession(['locale' => 'nl'])
            ->get('/?where=stadsappartement')
            ->assertOk()
            ->assertSee('Stadsappartement')
            ->assertDontSee('Strandvilla');
    }

    public function test_map_sits_behind_a_tab_and_is_default_hidden(): void
    {
        $this->makeAccommodations();

        $content = $this->withSession(['locale' => 'nl'])->get('/')->getContent();

        $this->assertStringContainsString('data-view="map"', $content);
        $this->assertStringContainsString('data-view="list"', $content);
        $this->assertMatchesRegularExpression('/data-panel="map" class="[^"]*hidden/', $content);
    }

    public function test_map_data_contains_coordinates_and_price(): void
    {
        $this->makeAccommodations();

        $content = $this->withSession(['locale' => 'nl'])->get('/')->getContent();

        preg_match('/<script type="application\/json" id="explore-map-data">(.*?)<\/script>/s', $content, $match);
        $data = json_decode(html_entity_decode($match[1] ?? '[]'), true);

        $this->assertCount(2, $data);

        $villa = collect($data)->firstWhere('name', 'Strandvilla');
        $this->assertSame(52.1, $villa['lat']);
        $this->assertStringContainsString('300', $villa['price']);
        $this->assertStringContainsString('/strandvilla', $villa['url']);
    }

    public function test_search_suggestions_give_places_and_accommodations(): void
    {
        $this->makeAccommodations();
        Property::query()->where('slug', 'strandvilla')->first()->update(['city' => 'Zandvoort', 'country' => 'NL']);
        Property::create([
            'name' => 'Verborgen huis', 'slug' => 'hidden', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => false, 'city' => 'Zandbergen',
        ]);

        // Plaats + woningnaam matchen; inactieve woningen doen niet mee.
        $response = $this->get('/api/search_suggestions?q=zand')->assertOk()->json('suggestions');

        $this->assertCount(1, $response);
        $this->assertSame('city', $response[0]['type']);
        $this->assertSame('Zandvoort', $response[0]['text']);

        $response = $this->get('/api/search_suggestions?q=strand')->assertOk()->json('suggestions');
        $accommodation = collect($response)->firstWhere('type', 'accommodation');
        $this->assertSame('Strandvilla', $accommodation['text']);
        $this->assertStringContainsString('/strandvilla', $accommodation['url']);

        // Te korte zoekterm: leeg.
        $this->assertSame([], $this->get('/api/search_suggestions?q=z')->json('suggestions'));
    }

    public function test_where_field_has_autocomplete_markup(): void
    {
        $this->makeAccommodations();

        $this->get('/')
            ->assertOk()
            ->assertSee('data-where-input', false)
            ->assertSee('data-where-suggestions', false)
            ->assertSee(route('api.search_suggestions'), false);
    }

    public function test_no_filters_or_map_at_a_accommodation(): void
    {
        Property::create([
            'name' => 'Solohuis', 'slug' => 'solohuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true, 'latitude' => 52.0, 'longitude' => 4.0,
        ]);
        Setting::set('site', 'single_accommodation_direct', '0');

        $this->withSession(['locale' => 'nl'])
            ->get('/')
            ->assertOk()
            ->assertDontSee(__('Explore the map'))
            ->assertDontSee(__('Price per night: all'));
    }
}
