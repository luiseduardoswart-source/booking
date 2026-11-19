<?php

namespace Tests\Feature;

use App\Models\ListingEvent;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListingAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function property(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Statistiekhuis', 'slug' => 'statistiekhuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ], $overrides));
    }

    private function photoFile(): string
    {
        $img = imagecreatetruecolor(400, 300);
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $path, 80);
        imagedestroy($img);

        return $path;
    }

    public function test_page_view_is_registered_at_visit(): void
    {
        $property = $this->property();

        $this->get("/{$property->slug}")->assertOk();

        $this->assertDatabaseHas('listing_events', [
            'type' => 'page_view',
            'property_id' => $property->id,
        ]);
    }

    public function test_device_is_derived_off_the_user_agent(): void
    {
        $property = $this->property();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605 Mobile/15E148'])
            ->get("/{$property->slug}")->assertOk();

        $this->assertSame('mobile', ListingEvent::where('type', 'page_view')->latest('id')->first()->device);
    }

    public function test_photo_tracking_records_only_own_photos(): void
    {
        Storage::fake('public');
        $property = $this->property();
        $media = $property->addMedia($this->photoFile())->toMediaCollection('photos');

        $this->post('/track/photo', ['property_id' => $property->id, 'media_id' => $media->id])
            ->assertNoContent();

        $this->assertDatabaseHas('listing_events', [
            'type' => 'photo_view',
            'media_id' => $media->id,
        ]);

        // A photo that does not belong to the accommodation is ignored.
        $this->post('/track/photo', ['property_id' => $property->id, 'media_id' => 999999])
            ->assertNoContent();

        $this->assertSame(1, ListingEvent::where('type', 'photo_view')->count());
    }

    public function test_all_statistic_widgets_run_their_query_without_errors(): void
    {
        Storage::fake('public');
        $property = $this->property();
        $media = $property->addMedia($this->photoFile())->toMediaCollection('photos');
        ListingEvent::record('page_view', $property);
        ListingEvent::record('quote', $property);
        ListingEvent::record('booking', $property);
        ListingEvent::record('photo_view', $property, ['media_id' => $media->id]);

        $chartWidgets = [
            \App\Filament\Statistics\PageViewsChart::class,
            \App\Filament\Statistics\ViewsPerAccommodationChart::class,
            \App\Filament\Statistics\ConversionFunnelChart::class,
            \App\Filament\Statistics\DeviceChart::class,
            \App\Filament\Statistics\LanguageChart::class,
            \App\Filament\Statistics\OriginChart::class,
        ];

        foreach ($chartWidgets as $class) {
            $method = new \ReflectionMethod($class, 'getData');
            $method->setAccessible(true);
            $data = $method->invoke(new $class);
            $this->assertArrayHasKey('datasets', $data, $class);
            $this->assertArrayHasKey('labels', $data, $class);
        }

        $stats = new \App\Filament\Statistics\StatisticsOverview;
        $getStats = new \ReflectionMethod($stats, 'getStats');
        $getStats->setAccessible(true);
        $this->assertNotEmpty($getStats->invoke($stats));
    }
}
