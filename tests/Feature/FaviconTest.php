<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Support\Favicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Uploading one image in the admin yields all favicon formats,
 * for the website and the admin.
 */
class FaviconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function set(string $key, int $width, int $height): void
    {
        $path = "branding/{$key}.png";
        Storage::disk('public')->put($path, UploadedFile::fake()->image('x.png', $width, $height)->getContent());
        Setting::set('branding', $key, $path);
    }

    public function test_a_upload_in_the_settings_makes_all_formats(): void
    {
        Livewire::actingAs($this->owner())
            ->test(Settings::class)
            ->fillForm(['company_name' => 'Testverhuur', 'branding_favicon' => UploadedFile::fake()->image('icon.png', 400, 400)])
            ->call('save')
            ->assertHasNoFormErrors();

        foreach (Favicon::FORMATS as $size => $name) {
            [$width, $height] = getimagesizefromstring(Storage::disk('public')->get("branding/favicon/{$name}"));
            $this->assertSame([$size, $size], [$width, $height], $name);
        }
    }

    public function test_a_rectangular_logo_is_square_without_cutting_off(): void
    {
        $this->set('logo', 300, 100);

        $this->assertTrue(Favicon::make());

        $image = imagecreatefromstring(Storage::disk('public')->get('branding/favicon/favicon-192.png'));
        $this->assertSame(192, imagesx($image));
        // Above and below the logo it is transparent.
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 96, 2))['alpha']);
    }

    public function test_the_website_and_the_admin_show_the_favicon(): void
    {
        $this->set('favicon', 64, 64);
        Favicon::make();

        $this->get('/')->assertOk()
            ->assertSee('rel="icon" type="image/png" sizes="32x32"', false)
            ->assertSee('rel="apple-touch-icon"', false);

        $this->actingAs($this->owner())->get('/admin')->assertOk()
            ->assertSee('favicon-32.png', false);
    }

    public function test_without_favicon_is_the_logo_used_also_as_the_there_already_was(): void
    {
        $this->set('logo', 120, 120);

        // The formats do not exist yet: the first page creates them.
        $this->get('/')->assertOk()->assertSee('favicon-32.png', false);
        $this->assertTrue(Storage::disk('public')->exists('branding/favicon/favicon-32.png'));
    }

    public function test_a_own_favicon_goes_for_the_logo(): void
    {
        $this->set('logo', 120, 120);
        $this->set('favicon', 64, 64);

        $this->assertSame('branding/favicon.png', Favicon::source());
    }

    public function test_without_image_no_favicon_and_old_formats_disappear(): void
    {
        $this->set('favicon', 64, 64);
        Favicon::make();
        Setting::set('branding', 'favicon', null);

        $this->assertFalse(Favicon::make());
        $this->assertFalse(Storage::disk('public')->exists('branding/favicon/favicon-32.png'));
        $this->get('/')->assertOk()->assertDontSee('apple-touch-icon', false);
    }

    public function test_a_svg_or_broken_file_breaks_nothing(): void
    {
        Storage::disk('public')->put('branding/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        Setting::set('branding', 'logo', 'branding/logo.svg');

        $this->assertFalse(Favicon::make());
        $this->get('/')->assertOk();

        // After that, do not retry on every page.
        Storage::disk('public')->put('branding/favicon/track.txt', 'x');
        $this->get('/')->assertOk();
        $this->assertTrue(Storage::disk('public')->exists('branding/favicon/track.txt'));
    }
}
