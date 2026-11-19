<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Page;
use App\Models\Property;
use App\Models\User;
use App\Services\Importing\MetadataImporter;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Files people upload are served from the site's own domain. Nothing they
 * upload may end up there as a page or image that runs a script: no SVG from
 * anyone but the owner, and a photo keeps the type of its content, whatever
 * its name says.
 */
class UploadSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function accommodation(): Property
    {
        return Property::create(['name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true]);
    }

    /** A real GIF with a script in its comment block, named as an HTML page. */
    private function gifNamedHtml(): UploadedFile
    {
        $image = imagecreatetruecolor(20, 20);
        $path = tempnam(sys_get_temp_dir(), 'gif');
        imagegif($image, $path);
        imagedestroy($image);
        file_put_contents($path, file_get_contents($path).'<script>alert(1)</script>');

        return new UploadedFile($path, 'x.html', null, null, true);
    }

    public function test_a_photo_keeps_the_type_of_its_content_not_of_its_name(): void
    {
        $media = $this->accommodation()->addPhoto($this->gifNamedHtml());

        $this->assertSame('x.gif', $media->file_name);
    }

    public function test_an_svg_is_no_photo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->accommodation()->addPhoto(UploadedFile::fake()->createWithContent('photo.svg', self::SVG));
    }

    public function test_only_the_owner_may_upload_an_svg_to_the_media_library(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'permissions' => ['media' => [Access::EDIT]]]);

        $this->actingAs($staff)->post('/admin-media/upload', [
            'path' => '',
            'files' => [UploadedFile::fake()->createWithContent('logo.svg', self::SVG), UploadedFile::fake()->image('beach.jpg')],
        ])->assertOk()->assertJsonCount(1, 'uploaded')->assertJsonPath('uploaded.0.name', 'beach.jpg');

        Storage::disk('public')->assertMissing('media/logo.svg');

        $owner = User::factory()->create(['role' => UserRole::Administrator]);
        $this->actingAs($owner)->post('/admin-media/upload', [
            'path' => '',
            'files' => [UploadedFile::fake()->createWithContent('logo.svg', self::SVG)],
        ])->assertOk()->assertJsonCount(1, 'uploaded');

        Storage::disk('public')->assertExists('media/logo.svg');
    }

    public function test_no_svg_into_the_photos_of_an_accommodation(): void
    {
        $property = $this->accommodation();
        $owner = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($owner)->post('/admin-media/upload', [
            'path' => "accommodations/{$property->id}",
            'files' => [UploadedFile::fake()->createWithContent('photo.svg', self::SVG)],
        ])->assertOk()->assertJsonCount(0, 'uploaded');

        $this->assertCount(0, $property->getMedia('photos'));
    }

    public function test_a_hero_link_cannot_run_a_script(): void
    {
        $heroes = Page::cleanHeroes([
            ['image' => 'a.jpg', 'link' => 'javascript:alert(1)'],
            ['image' => 'b.jpg', 'link' => ' JaVaScRiPt:alert(1)'],
            ['image' => 'c.jpg', 'link' => 'data:text/html,<script>alert(1)</script>'],
            ['image' => 'd.jpg', 'link' => '//evil.example'],
            ['image' => 'e.jpg', 'link' => 'https://example.com/offer'],
            ['image' => 'f.jpg', 'link' => '/contact'],
            ['image' => 'g.jpg', 'link' => '#book'],
        ]);

        $this->assertSame(['', '', '', '', 'https://example.com/offer', '/contact', '#book'], array_column($heroes, 'link'));
    }

    public function test_an_imported_title_with_an_encoded_tag_stays_text(): void
    {
        $importer = new MetadataImporter;
        $clean = (new \ReflectionMethod($importer, 'clean'))->invoke($importer, 'Loft &lt;img src=x onerror=alert(1)&gt; Cartagena');

        $this->assertStringNotContainsString('<', $clean);
        $this->assertStringContainsString('Loft', $clean);
    }
}
