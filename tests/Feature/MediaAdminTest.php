<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaAlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_only_the_administrator_may_at_the_media(): void
    {
        Storage::fake('public');

        // guest and ordinary user first (actingAs stays in effect afterwards)
        $this->getJson('/admin-media/list')->assertStatus(403);

        $guest = User::factory()->create(['role' => UserRole::Staff]);
        $this->actingAs($guest)->getJson('/admin-media/list')->assertStatus(403);
    }

    public function test_list_makes_the_root_folder_on_and_gives_files_back(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/strand.jpg', 'x');

        $this->actingAs($this->admin())
            ->getJson('/admin-media/list')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('files.0.name', 'strand.jpg')
            // alt automatically from the file name
            ->assertJsonPath('files.0.alt', 'Strand')
            ->assertJsonPath('files.0.alt_custom', false);
    }

    public function test_upload_slugifies_the_name_and_rejects_foreign_extensions(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post('/admin-media/upload', [
                'path' => '',
                'files' => [
                    UploadedFile::fake()->image('Mooi Strand.JPG'),
                    UploadedFile::fake()->create('kwaad.php', 1),
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'uploaded')
            ->assertJsonPath('uploaded.0.name', 'mooi-strand.jpg');

        Storage::disk('public')->assertExists('media/mooi-strand.jpg');
        Storage::disk('public')->assertMissing('media/kwaad.php');
    }

    public function test_folder_create_rename_and_alt_text(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        $disk->put('media/foto.jpg', 'x');

        $this->actingAs($this->admin());

        $this->postJson('/admin-media/folder', ['path' => '', 'name' => 'Vakantie Huis'])->assertOk();
        $this->assertTrue($disk->exists('media/vakantie-huis'));

        // set alt and clear it again
        $this->postJson('/admin-media/alt', ['path' => 'media/foto.jpg', 'alt' => 'Zicht op zee'])
            ->assertOk()->assertJsonPath('alt', 'Zicht op zee')->assertJsonPath('alt_custom', true);

        // rename: alt moves along
        $this->postJson('/admin-media/rename', ['path' => 'media/foto.jpg', 'name' => 'Zee Zicht'])
            ->assertOk()->assertJsonPath('path', 'media/zee-zicht.jpg');
        $this->assertSame('Zicht op zee', MediaAlt::forPath('media/zee-zicht.jpg'));

        // existing name = clean 422, not silently overwritten
        $disk->put('media/ander.jpg', 'y');
        $this->postJson('/admin-media/rename', ['path' => 'media/ander.jpg', 'name' => 'zee-zicht'])
            ->assertStatus(422);
    }

    public function test_delete_and_multiple_at_once_with_path_check(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        $disk->put('media/een.jpg', 'x');
        $disk->put('media/twee.jpg', 'x');
        $disk->put('media/map/drie.jpg', 'x');

        $this->actingAs($this->admin());

        // path outside the media folder: the whole request is rejected
        $this->postJson('/admin-media/delete-many', ['items' => [
            ['path' => 'media/een.jpg', 'type' => 'file'],
            ['path' => 'avatars/geheim.jpg', 'type' => 'file'],
        ]])->assertStatus(422);
        $this->assertTrue($disk->exists('media/een.jpg'));

        // path traversal is refused
        $this->postJson('/admin-media/delete', ['path' => 'media/../.env', 'type' => 'file'])
            ->assertStatus(422);

        $this->postJson('/admin-media/delete-many', ['items' => [
            ['path' => 'media/een.jpg', 'type' => 'file'],
            ['path' => 'media/map', 'type' => 'dir'],
        ]])->assertOk()->assertJsonPath('deleted', 2);

        $this->assertFalse($disk->exists('media/een.jpg'));
        $this->assertFalse($disk->exists('media/map/drie.jpg'));
        $this->assertTrue($disk->exists('media/twee.jpg'));
    }

    public function test_photo_editor_saves_as_copy_or_overrides(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        $disk->put('media/foto.jpg', 'original');

        $this->actingAs($this->admin());

        // copy (default)
        $this->post('/admin-media/edit', [
            'path' => 'media/foto.jpg',
            'file' => UploadedFile::fake()->image('edited.jpg'),
        ])->assertOk()->assertJsonPath('path', 'media/foto-edited.jpg');
        $this->assertSame('original', $disk->get('media/foto.jpg'));

        // overwrite
        $this->post('/admin-media/edit', [
            'path' => 'media/foto.jpg',
            'file' => UploadedFile::fake()->image('edited.jpg'),
            'overwrite' => '1',
        ])->assertOk()->assertJsonPath('path', 'media/foto.jpg');
        $this->assertNotSame('original', $disk->get('media/foto.jpg'));
    }

    public function test_media_page_is_in_the_admin(): void
    {
        $this->actingAs($this->admin())
            ->get(\App\Filament\Pages\MediaManager::getUrl())
            ->assertOk()
            ->assertSee('media-inline', false)
            ->assertSee('media-manager.js', false);
    }

    public function test_page_admin_loads_the_picker_and_the_hero_script_along(): void
    {
        // The Pages forms open in Livewire modals; scripts that only arrive
        // during morphing do not run. So the media picker and the hero script
        // must already be in the initial HTML (render hook).
        $this->actingAs($this->admin())
            ->get(\App\Filament\Resources\Pages\PageResource::getUrl('index'))
            ->assertOk()
            ->assertSee('media-modal', false)
            ->assertSee('image-editor-modal', false)
            ->assertSee('media-manager.js', false)
            ->assertSee('hero-images.js', false);
    }
}
