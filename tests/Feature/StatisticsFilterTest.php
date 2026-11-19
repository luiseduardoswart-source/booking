<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Statistics;
use App\Filament\Statistics\ConversionFunnelChart;
use App\Filament\Statistics\DeviceChart;
use App\Filament\Statistics\LanguageChart;
use App\Filament\Statistics\OriginChart;
use App\Filament\Statistics\PageViewsChart;
use App\Filament\Statistics\StatisticsOverview;
use App\Filament\Statistics\TopPhotos;
use App\Filament\Statistics\ViewsPerAccommodationChart;
use App\Models\ListingEvent;
use App\Models\Property;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The accommodation filter on the Statistics page: choosing an accommodation
 * narrows every widget to that accommodation; empty shows all of them.
 */
class StatisticsFilterTest extends TestCase
{
    use RefreshDatabase;

    private Property $beach;

    private Property $cabin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->beach = $this->accommodation('Beach house', 'beach');
        $this->cabin = $this->accommodation('Jungle cabin', 'cabin');

        // Beach house: 3 views by 2 visitors, 1 price request, 1 booking.
        // Jungle cabin: 1 view by a third visitor, who also saw the home
        // page, which belongs to no accommodation.
        foreach ([[$this->beach, 'a'], [$this->beach, 'a'], [$this->beach, 'b'], [$this->cabin, 'c'], [null, 'c']] as [$property, $visitor]) {
            ListingEvent::record('page_view', $property, ['session_id' => $visitor]);
        }
        ListingEvent::record('quote', $this->beach);
        ListingEvent::record('booking', $this->beach);
    }

    private function accommodation(string $name, string $slug): Property
    {
        return Property::create([
            'name' => $name, 'slug' => $slug, 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    private function photo(Property $property)
    {
        $img = imagecreatetruecolor(400, 300);
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $path, 80);
        imagedestroy($img);

        return $property->addMedia($path)->toMediaCollection('photos');
    }

    /** The chart data of a widget, with the page filter as the Statistics page passes it. */
    private function chart(string $class, ?Property $property): array
    {
        $widget = new $class;
        $widget->pageFilters = ['property_id' => $property?->id];
        $method = new \ReflectionMethod($widget, 'getData');

        return $method->invoke($widget);
    }

    private function total(array $chart): int
    {
        return array_sum($chart['datasets'][0]['data'] ?? []);
    }

    public function test_the_statistics_page_has_an_accommodation_filter_with_every_accommodation(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($administrator)->test(Statistics::class)
            ->assertFormFieldExists('property_id', 'filtersForm', function (Select $field): bool {
                return $field->getOptions() === [$this->beach->id => 'Beach house', $this->cabin->id => 'Jungle cabin'];
            })
            ->set('filters.property_id', $this->cabin->id)
            ->assertSet('filters.property_id', $this->cabin->id);
    }

    public function test_the_page_view_charts_count_only_the_chosen_accommodation(): void
    {
        foreach ([PageViewsChart::class, DeviceChart::class, LanguageChart::class, OriginChart::class] as $class) {
            $this->assertSame(5, $this->total($this->chart($class, null)), $class);
            $this->assertSame(3, $this->total($this->chart($class, $this->beach)), $class);
            $this->assertSame(1, $this->total($this->chart($class, $this->cabin)), $class);
        }
    }

    public function test_the_accommodation_charts_show_only_the_chosen_accommodation(): void
    {
        $this->assertSame(['Beach house', 'Jungle cabin'], $this->chart(ViewsPerAccommodationChart::class, null)['labels']);
        $this->assertSame(['Jungle cabin'], $this->chart(ViewsPerAccommodationChart::class, $this->cabin)['labels']);

        $funnel = $this->chart(ConversionFunnelChart::class, $this->beach);
        $this->assertSame(['Beach house'], $funnel['labels']);
        $this->assertSame([[3], [1], [1]], array_column($funnel['datasets'], 'data'));
    }

    public function test_the_overview_counts_only_the_chosen_accommodation(): void
    {
        $values = function (?Property $property): array {
            $widget = new StatisticsOverview;
            $widget->pageFilters = ['property_id' => $property?->id];
            $method = new \ReflectionMethod($widget, 'getStats');

            return array_map(fn ($stat) => (string) $stat->getValue(), $method->invoke($widget));
        };

        // Views, unique visitors, price requests, bookings.
        $this->assertSame(['5', '3', '1', '1'], $values(null));
        $this->assertSame(['3', '2', '1', '1'], $values($this->beach));
        $this->assertSame(['1', '1', '0', '0'], $values($this->cabin));
    }

    public function test_the_most_viewed_photos_show_only_the_chosen_accommodation(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $beachPhoto = $this->photo($this->beach);
        $cabinPhoto = $this->photo($this->cabin);
        ListingEvent::record('photo_view', $this->beach, ['media_id' => $beachPhoto->id]);
        ListingEvent::record('photo_view', $this->cabin, ['media_id' => $cabinPhoto->id]);

        $all = Livewire::actingAs($administrator)->test(TopPhotos::class)->html();
        $this->assertStringContainsString($beachPhoto->getUrl('thumb'), $all);
        $this->assertStringContainsString($cabinPhoto->getUrl('thumb'), $all);

        $cabinOnly = Livewire::actingAs($administrator)
            ->test(TopPhotos::class, ['pageFilters' => ['property_id' => $this->cabin->id]])->html();
        $this->assertStringContainsString($cabinPhoto->getUrl('thumb'), $cabinOnly);
        $this->assertStringNotContainsString($beachPhoto->getUrl('thumb'), $cabinOnly);
    }
}
