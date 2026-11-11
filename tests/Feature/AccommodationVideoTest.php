<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\AccommodationPhotos;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Videos between the photos of an accommodation: mp4 and webm up to 100 MB.
 * A video never becomes the main photo: at the top of the page, on the
 * cards and in the table there is always a photo.
 */
class AccommodationVideoTest extends TestCase
{
    use RefreshDatabase;

    /** The start of a real mp4, webm and mov file: enough for the server to recognise the format. */
    private const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

    private const WEBM = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02";

    private const MOV = "\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  ";

    private Property $accommodation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);
    }

    private function video(string $name, string $start = self::MP4, ?int $kilobytes = null): UploadedFile
    {
        // The browser sends the type along; on the server Livewire reads it from the contents.
        $types = [self::MP4 => 'video/mp4', self::WEBM => 'video/webm', self::MOV => 'video/quicktime'];
        $file = UploadedFile::fake()->createWithContent($name, $start.str_repeat("\x00", 256))->mimeType($types[$start]);

        return $kilobytes === null ? $file : $file->size($kilobytes);
    }

    private function upload(UploadedFile $file): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(AccommodationPhotos::class, ['property' => $this->accommodation])
            ->set('upload', $file);
    }

    private function photo(string $name): Media
    {
        return $this->accommodation->addPhoto(UploadedFile::fake()->image($name, 800, 600));
    }

    private function addVideo(string $name = 'trailer.mp4'): Media
    {
        return $this->accommodation->addPhoto($this->video($name));
    }

    /** @return array<int, string> */
    private function fileNames(): array
    {
        return $this->accommodation->fresh()->getMedia('photos')->pluck('file_name')->all();
    }

    public function test_an_mp4_and_a_webm_video_are_added_between_the_photos(): void
    {
        $this->photo('kitchen.jpg');

        $this->upload($this->video('Trailer Strand.MP4'))->assertHasNoErrors()->assertSet('upload', null);
        $this->upload($this->video('tour.webm', self::WEBM))->assertHasNoErrors();

        $this->assertSame(['kitchen.jpg', 'trailer-strand.mp4', 'tour.webm'], $this->fileNames());

        $video = $this->accommodation->fresh()->getMedia('photos')[1];
        $this->assertTrue(Property::isVideo($video));
        $this->assertFalse(Property::isVideo($this->accommodation->getFirstMedia('photos')));
    }

    public function test_a_video_may_be_larger_than_a_photo_up_to_the_video_limit(): void
    {
        // The example video was 20.8 MB; a video between the photo and video limit.
        $this->upload($this->video('long.mp4', kilobytes: Property::MAX_PHOTO_KB + 5 * 1024))->assertHasNoErrors();
        $this->assertSame(['long.mp4'], $this->fileNames());

        $this->upload($this->video('too-long.mp4', kilobytes: Property::MAX_VIDEO_KB + 1))->assertHasErrors('upload');
        $this->assertSame(['long.mp4'], $this->fileNames());
        $this->assertSame(100 * 1024, Property::MAX_VIDEO_KB);
    }

    public function test_the_media_library_itself_accepts_a_file_above_its_old_10_mb_limit(): void
    {
        // A real file of 11 MB: Spatie checks the size on disk, not the size the browser reports.
        $path = tempnam(sys_get_temp_dir(), 'video');
        file_put_contents($path, self::MP4.str_repeat("\x00", 11 * 1024 * 1024));

        $media = $this->accommodation->addPhoto(new UploadedFile($path, 'big.mp4', 'video/mp4', null, true));

        $this->assertTrue(Property::isVideo($media));
        $this->assertGreaterThanOrEqual(Property::MAX_VIDEO_KB * 1024, config('media-library.max_file_size'));
    }

    public function test_a_mov_video_is_refused_because_not_every_browser_plays_it(): void
    {
        $this->upload($this->video('iphone.mov', self::MOV))->assertHasErrors('upload');

        $this->assertSame([], $this->fileNames());
    }

    public function test_the_main_photo_is_never_a_video(): void
    {
        $video = $this->addVideo();
        $first = $this->photo('terrace.jpg');
        $second = $this->photo('kitchen.jpg');

        $property = $this->accommodation->fresh();

        $this->assertTrue($property->coverPhoto()->is($first));
        $this->assertSame($first->getUrl('large'), $property->coverPhotoUrl('large'));
        // The site: the main photo first, then the rest in the owner's order.
        $this->assertSame([$first->id, $video->id, $second->id], $property->gallery()->modelKeys());
    }

    public function test_only_videos_means_no_main_photo(): void
    {
        $this->addVideo();

        $this->assertNull($this->accommodation->fresh()->coverPhoto());
        $this->assertSame('', $this->accommodation->fresh()->coverPhotoUrl('thumb'));
    }

    public function test_the_site_shows_a_photo_on_top_and_plays_the_video(): void
    {
        $video = $this->addVideo();
        $photo = $this->photo('terrace.jpg');

        $this->get(route('properties.show', $this->accommodation->slug))
            ->assertOk()
            // The large tile is the photo, the video plays its original (no resized version).
            ->assertSeeInOrder([$photo->getUrl('large'), $video->getUrl().'#t=0.1'], false)
            ->assertSee('<video src="'.$video->getUrl().'#t=0.1" controls', false);

        // The card in the overview uses the photo, never the video.
        $this->blade('<x-property-card :property="$property" />', ['property' => $this->accommodation->fresh()])
            ->assertSee($photo->getUrl('thumb'), false)
            ->assertDontSee($video->getUrl(), false);
    }

    public function test_the_admin_list_shows_the_video_without_the_photo_editor(): void
    {
        $video = $this->addVideo();
        $photo = $this->photo('terrace.jpg');

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(AccommodationPhotos::class, ['property' => $this->accommodation])
            ->assertSeeHtml('data-video="'.$video->id.'"')
            ->assertSeeHtml('accept="image/*,video/mp4,video/webm"')
            ->assertSeeHtml('data-path="accommodations/'.$this->accommodation->id.'/'.$photo->id.'"')
            ->assertDontSeeHtml('data-path="accommodations/'.$this->accommodation->id.'/'.$video->id.'"');
    }

    public function test_the_api_lists_photos_and_videos_apart(): void
    {
        $video = $this->addVideo();
        $photo = $this->photo('terrace.jpg');

        $this->getJson("/api/v1/properties/{$this->accommodation->id}")->assertOk()
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.photos.0.large', $photo->getUrl('large'))
            ->assertJsonPath('data.videos.0.url', $video->getUrl());
    }

    public function test_the_media_library_accepts_a_video_in_the_accommodation_folder(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->post('/admin-media/upload', [
                'path' => 'accommodations/'.$this->accommodation->id,
                'files' => [$this->video('tour.mp4'), UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf')],
            ])
            ->assertOk();

        $this->assertSame(['tour.mp4'], $this->fileNames());
    }
}
