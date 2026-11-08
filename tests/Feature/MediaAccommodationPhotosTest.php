<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Accommodation photos can be managed from Media management, even though they are not
 * in the media folder but in the media library. Every operation must update both the file
 * and the database row.
 */
class MediaAccommodationPhotosTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function photoFile(): string
    {
        $img = imagecreatetruecolor(800, 600);
        imagefilledrectangle($img, 0, 0, 800, 600, imagecolorallocate($img, 100, 150, 200));
        $path = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $path, 85);
        imagedestroy($img);

        return $path;
    }

    /** @return array{0: Property, 1: Media} */
    private function accommodationWithPhoto(): array
    {
        Storage::fake('public');

        $property = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true,
        ]);

        return [$property, $property->addMedia($this->photoFile())->toMediaCollection('photos')];
    }

    public function test_the_root_folder_shows_accommodation_photos_as_own_map(): void
    {
        $this->accommodationWithPhoto();

        $this->actingAs($this->admin())
            ->getJson('/admin-media/list')
            ->assertOk()
            ->assertJsonPath('dirs.0.path', 'accommodations');
    }

    public function test_the_map_contains_a_sub_dir_per_accommodation(): void
    {
        [$property] = $this->accommodationWithPhoto();

        $this->actingAs($this->admin())
            ->getJson('/admin-media/list?path=accommodations')
            ->assertOk()
            ->assertJsonPath('dirs.0.name', 'Strandhuis')
            ->assertJsonPath('dirs.0.path', 'accommodations/'.$property->id);
    }

    public function test_the_photos_of_a_accommodation_are_shown(): void
    {
        [$property, $media] = $this->accommodationWithPhoto();

        $this->actingAs($this->admin())
            ->getJson('/admin-media/list?path=accommodations/'.$property->id)
            ->assertOk()
            ->assertJsonPath('files.0.path', 'accommodations/'.$property->id.'/'.$media->id)
            ->assertJsonPath('files.0.is_image', true);
    }

    public function test_alt_text_writes_the_caption_of_the_photo(): void
    {
        [$property, $media] = $this->accommodationWithPhoto();

        $this->actingAs($this->admin())
            ->postJson('/admin-media/alt', [
                'path' => "accommodations/{$property->id}/{$media->id}",
                'alt' => 'Uitzicht op zee',
            ])
            ->assertOk()
            ->assertJsonPath('alt', 'Uitzicht op zee');

        $this->assertSame('Uitzicht op zee', $media->fresh()->getCustomProperty('label'));
    }

    public function test_rename_moves_the_file_and_works_the_database_at(): void
    {
        [$property, $media] = $this->accommodationWithPhoto();
        $oldPath = $media->getPathRelativeToRoot();

        $this->actingAs($this->admin())
            ->postJson('/admin-media/rename', [
                'path' => "accommodations/{$property->id}/{$media->id}",
                'name' => 'Zonsondergang',
            ])
            ->assertOk();

        $this->assertSame('zonsondergang.jpg', $media->fresh()->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->fresh()->getPathRelativeToRoot()));
        $this->assertFalse(Storage::disk('public')->exists($oldPath));
    }

    public function test_delete_fetches_the_photo_also_off_the_accommodation(): void
    {
        [$property, $media] = $this->accommodationWithPhoto();

        $this->actingAs($this->admin())
            ->postJson('/admin-media/delete', [
                'path' => "accommodations/{$property->id}/{$media->id}",
                'type' => 'file',
            ])
            ->assertOk();

        $this->assertNull(Media::find($media->id));
        $this->assertCount(0, $property->fresh()->getMedia('photos'));
    }

    public function test_a_accommodation_dir_itself_can_you_not_throw_away(): void
    {
        [$property] = $this->accommodationWithPhoto();

        $this->actingAs($this->admin())
            ->postJson('/admin-media/delete', [
                'path' => 'accommodations/'.$property->id,
                'type' => 'dir',
            ])
            ->assertStatus(422);

        $this->assertCount(1, $property->fresh()->getMedia('photos'));
    }

    public function test_upload_adds_the_photo_on_the_accommodation(): void
    {
        [$property] = $this->accommodationWithPhoto();

        $this->actingAs($this->admin())
            ->post('/admin-media/upload', [
                'path' => 'accommodations/'.$property->id,
                'files' => [UploadedFile::fake()->image('nieuw.jpg', 800, 600)],
            ])
            ->assertOk();

        $this->assertCount(2, $property->fresh()->getMedia('photos'));
    }

    public function test_the_photo_of_a_other_accommodation_is_not_to_access(): void
    {
        [$property, $media] = $this->accommodationWithPhoto();

        $other = Property::create([
            'name' => 'Boshut', 'slug' => 'boshut', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);

        // Photo id of property A, but with property B in the path: must fail.
        $this->actingAs($this->admin())
            ->postJson('/admin-media/delete', [
                'path' => "accommodations/{$other->id}/{$media->id}",
                'type' => 'file',
            ])
            ->assertStatus(404);

        $this->assertNotNull(Media::find($media->id));
    }

    public function test_the_photo_editor_can_the_original_replace(): void
    {
        [$property, $media] = $this->accommodationWithPhoto();
        $oldSize = $media->size;

        $this->actingAs($this->admin())
            ->post('/admin-media/edit', [
                'path' => "accommodations/{$property->id}/{$media->id}",
                'overwrite' => true,
                'file' => UploadedFile::fake()->image('edited.jpg', 400, 300),
            ])
            ->assertOk();

        // Same photo, same place in the property, but a different file.
        $this->assertCount(1, $property->fresh()->getMedia('photos'));
        $this->assertNotSame($oldSize, $media->fresh()->size);
    }

    public function test_the_photo_editor_can_there_a_copy_next_to_set(): void
    {
        [$property, $media] = $this->accommodationWithPhoto();

        $this->actingAs($this->admin())
            ->post('/admin-media/edit', [
                'path' => "accommodations/{$property->id}/{$media->id}",
                'file' => UploadedFile::fake()->image('edited.jpg', 400, 300),
            ])
            ->assertOk();

        $photos = $property->fresh()->getMedia('photos');
        $this->assertCount(2, $photos);
        $this->assertTrue($photos->contains(fn ($m) => str_contains($m->file_name, '-edited')));
    }

    public function test_regular_media_files_stay_simply_work(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/strand.jpg', 'x');

        $this->actingAs($this->admin())
            ->getJson('/admin-media/list')
            ->assertOk()
            ->assertJsonPath('files.0.name', 'strand.jpg');
    }
}
