<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Statistics\TopPhotos;
use App\Models\ListingEvent;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The photo viewer on the accommodation page: one photo at a time with its
 * description, arrows, a counter and thumbnails; a tile opens its own photo.
 * And the statistics show the most viewed photos as photos.
 */
class PhotoViewerTest extends TestCase
{
    use RefreshDatabase;

    private int $houses = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function accommodation(string $name = 'Strandhuis', int $photos = 6): Property
    {
        $this->houses++;
        $property = Property::create([
            'name' => $name, 'slug' => "house-{$this->houses}", 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true, 'base_price_cents' => 10000,
        ]);

        for ($i = 1; $i <= $photos; $i++) {
            $property->addMedia($this->photoFile())->toMediaCollection('photos');
        }

        return $property;
    }

    private function photoFile(): string
    {
        $img = imagecreatetruecolor(400, 300);
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $path, 80);
        imagedestroy($img);

        return $path;
    }

    public function test_the_viewer_shows_one_photo_at_a_time_with_its_description_counter_and_thumbnails(): void
    {
        app()->setLocale('en');
        $property = $this->accommodation();
        $photos = $property->gallery();
        $photos[2]->setCustomProperty('label', 'King bed with ocean view')->save();
        $photos[3]->setCustomProperty('label', 'Pool')->setCustomProperty('label_translations', ['en' => 'Rooftop pool'])->save();

        $html = $this->get("/{$property->slug}")->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'data-photo-slide data-index='));
        $this->assertSame(6, substr_count($html, 'data-photo-thumb data-index='));
        $this->assertStringContainsString('data-photo-counter', $html);
        $this->assertStringContainsString('data-caption="King bed with ocean view"', $html);
        // The description in the visitor's language comes first.
        $this->assertStringContainsString('data-caption="Rooftop pool"', $html);
        $this->assertStringContainsString('aria-label="'.__('Next photo').'"', $html);
        $this->assertStringContainsString('aria-label="'.__('Previous photo').'"', $html);
        // The row of thumbnails scrolls with an arrow at either end.
        $this->assertStringContainsString('data-photo-thumbs-prev aria-label="'.__('Show earlier thumbnails').'"', $html);
        $this->assertStringContainsString('data-photo-thumbs-next aria-label="'.__('Show later thumbnails').'"', $html);

        // Every photo is tracked from the viewer.
        foreach ($photos as $photo) {
            $this->assertStringContainsString('data-photo-track="'.$photo->id.'"', $html);
        }
    }

    public function test_a_tile_on_the_page_opens_the_viewer_at_that_photo(): void
    {
        $property = $this->accommodation();

        $html = $this->get("/{$property->slug}")->assertOk()->getContent();

        // The large photo and the four tiles open their own photo; "Show all photos" starts at the first.
        foreach ([0, 1, 2, 3, 4] as $index) {
            $this->assertStringContainsString('data-photo-open="'.$index.'"', $html);
        }
        $this->assertStringNotContainsString('photo_dialog.showModal()', $html);
    }

    /** The colour from Settings → Colours & style colours the arrows and the current thumbnail. */
    public function test_the_photo_viewer_colour_is_used_for_the_arrows_and_the_current_thumbnail(): void
    {
        $property = $this->accommodation();
        $rule = '[data-photo-prev], [data-photo-next] { background-color: var(--color-label)';

        // Empty: the default white arrows.
        $this->assertStringNotContainsString($rule, $this->get("/{$property->slug}")->getContent());

        \App\Models\Setting::set('frontend', 'label_color', '#155e75');
        $html = $this->get("/{$property->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('--color-label: #155e75', $html);
        $this->assertStringContainsString($rule, $html);
        $this->assertStringContainsString('[data-photo-thumb][data-active] { box-shadow: 0 0 0 2px var(--color-label)', $html);
    }

    /** Statistics: the most viewed photos as photos, with accommodation and number of views. */
    public function test_the_statistics_show_the_most_viewed_photos_with_their_accommodation(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $beach = $this->accommodation('Beach house', 2);
        $cabin = $this->accommodation('Jungle cabin', 1);
        [$first, $second] = $beach->gallery()->all();
        $cabinPhoto = $cabin->gallery()->first();
        $first->setCustomProperty('label', 'Ocean view from the bed')->save();

        foreach ([[$first, 5], [$cabinPhoto, 3], [$second, 1]] as [$photo, $views]) {
            for ($i = 0; $i < $views; $i++) {
                ListingEvent::record('photo_view', $photo->model, ['media_id' => $photo->id]);
            }
        }

        // A photo that no longer exists says nothing and is left out.
        ListingEvent::record('photo_view', $beach, ['media_id' => 999999]);
        // Older than 30 days does not count.
        ListingEvent::query()->create(['type' => 'photo_view', 'property_id' => $beach->id, 'media_id' => $second->id, 'created_at' => now()->subDays(40)]);

        $html = Livewire::actingAs($administrator)->test(TopPhotos::class)->html();

        $this->assertStringContainsString($first->getUrl('thumb'), $html);
        $this->assertStringContainsString($cabinPhoto->getUrl('thumb'), $html);
        $this->assertStringContainsString('Ocean view from the bed', $html);
        $this->assertStringContainsString('Beach house', $html);
        $this->assertStringContainsString('Jungle cabin', $html);
        $this->assertStringNotContainsString('999999', $html);

        // Most viewed first, with the number of views.
        $this->assertLessThan(strpos($html, $cabinPhoto->getUrl('thumb')), strpos($html, $first->getUrl('thumb')));
        $this->assertLessThan(strpos($html, $second->getUrl('thumb')), strpos($html, $cabinPhoto->getUrl('thumb')));
        $this->assertMatchesRegularExpression('/data-views="5"/', $html);
        $this->assertMatchesRegularExpression('/data-views="1"/', $html);
    }
}
