<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Setting;
use App\Support\SiteLayouts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeTwoAccommodations(): void
    {
        foreach (['een', 'twee'] as $slug) {
            Property::create([
                'name' => "Huis {$slug}", 'slug' => "huis-{$slug}", 'type' => 'holiday_home',
                'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            ]);
        }
    }

    public function test_the_bundled_fullscreen_layout_is_found(): void
    {
        $layouts = SiteLayouts::all();

        $this->assertArrayHasKey('default', $layouts);
        $this->assertArrayHasKey('fullscreen', $layouts);
        $this->assertSame('Fullscreen magazine', $layouts['fullscreen']['name']);
    }

    public function test_default_layout_shows_the_card_grid(): void
    {
        $this->makeTwoAccommodations();

        $this->get('/')
            ->assertOk()
            ->assertSee(__('Our accommodations'))
            ->assertDontSee('data-layout="fullscreen"', false);
    }

    public function test_chosen_layout_replaces_the_homepage(): void
    {
        $this->makeTwoAccommodations();
        Setting::set('frontend', 'layout', 'fullscreen');

        $this->get('/')
            ->assertOk()
            ->assertSee('data-layout="fullscreen"', false)
            ->assertSee('Huis een')
            ->assertSee(__('View & book'));
    }

    public function test_pages_without_template_version_fall_back_on_the_default(): void
    {
        $this->makeTwoAccommodations();
        Setting::set('frontend', 'layout', 'fullscreen');

        // The fullscreen template ships no detail page of its own → default view.
        $this->get('/huis-een')
            ->assertOk()
            ->assertSee('Huis een');
    }

    public function test_unknown_layout_breaks_the_site_not(): void
    {
        $this->makeTwoAccommodations();
        Setting::set('frontend', 'layout', 'bestaat-niet');

        $this->assertSame(SiteLayouts::DEFAULT, SiteLayouts::active());

        $this->get('/')->assertOk()->assertSee(__('Our accommodations'));
    }

    public function test_path_trick_in_the_setting_stays_inside_the_template_dir(): void
    {
        $this->assertStringEndsWith('views/templates/passwd', SiteLayouts::path('../../../etc/passwd'));
    }

    public function test_fullscreen_layout_changes_also_the_detail_page(): void
    {
        $this->makeTwoAccommodations();
        Setting::set('frontend', 'layout', 'fullscreen');

        $this->get('/huis-een')
            ->assertOk()
            ->assertSee('data-hero="fullscreen"', false)
            ->assertSee('Huis een');
    }

    public function test_zip_upload_installs_a_template_complete(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'tpl').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('mijn-thema/template.json', json_encode(['name' => 'Mijn thema', 'description' => 'Testthema']));
        $zip->addFromString('mijn-thema/site/home.blade.php', '<x-site-layout><p data-thema="mijn-thema">Thuis</p></x-site-layout>');
        $zip->addFromString('mijn-thema/assets/template.css', 'body { --thema: 1; }');
        $zip->addFromString('mijn-thema/../evil.blade.php', 'malicious');   // path-traversal
        $zip->addFromString('mijn-thema/hack.php', '<?php echo "kaal php"; ?>'); // not .blade.php
        $zip->close();

        try {
            $name = SiteLayouts::installZip($zipPath);

            $this->assertSame('mijn-thema', $name);
            $this->assertFileExists(SiteLayouts::path($name).'/site/home.blade.php');
            $this->assertFileExists(SiteLayouts::assetsPath($name).'/template.css');
            $this->assertFileDoesNotExist(SiteLayouts::path($name).'/hack.php');
            $this->assertFileDoesNotExist(dirname(SiteLayouts::path($name)).'/evil.blade.php');
            $this->assertSame('Mijn thema', SiteLayouts::all()[$name]['name']);

            // The installed template works right away: own homepage + own CSS.
            $this->makeTwoAccommodations();
            Setting::set('frontend', 'layout', $name);

            $this->get('/')
                ->assertOk()
                ->assertSee('data-thema="mijn-thema"', false)
                ->assertSee('templates/mijn-thema/template.css', false);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory(SiteLayouts::path('mijn-thema'));
            \Illuminate\Support\Facades\File::deleteDirectory(SiteLayouts::assetsPath('mijn-thema'));
            @unlink($zipPath);
        }
    }

    public function test_example_template_is_downloadable_and_direct_reusable(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Administrator]);

        // The download button delivers a zip.
        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Pages\Settings::class)
            ->call('downloadExampleTemplate')
            ->assertFileDownloaded('example-template.zip');

        // Round trip: the same zip format can be installed again right away.
        $zipPath = tempnam(sys_get_temp_dir(), 'example').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);

        foreach (\Illuminate\Support\Facades\File::allFiles(SiteLayouts::path('fullscreen')) as $file) {
            $zip->addFile($file->getPathname(), 'mijn-template/'.str_replace('\\', '/', $file->getRelativePathname()));
        }
        $zip->addFromString('mijn-template/assets/template.css', '/* eigen css */');
        $zip->close();

        try {
            $name = SiteLayouts::installZip($zipPath);

            $this->assertSame('mijn-template', $name);
            $this->assertFileExists(SiteLayouts::path($name).'/site/home.blade.php');
            $this->assertFileExists(SiteLayouts::path($name).'/site/properties/partials/hero.blade.php');
            $this->assertFileExists(SiteLayouts::assetsPath($name).'/template.css');

            $this->makeTwoAccommodations();
            Setting::set('frontend', 'layout', $name);

            $this->get('/')->assertOk()->assertSee('data-layout="fullscreen"', false);
            $this->get('/huis-een')->assertOk()->assertSee('data-hero="fullscreen"', false);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory(SiteLayouts::path('mijn-template'));
            \Illuminate\Support\Facades\File::deleteDirectory(SiteLayouts::assetsPath('mijn-template'));
            @unlink($zipPath);
        }
    }

    public function test_zip_without_template_json_is_rejected(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'tpl').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('los-bestand.txt', 'geen template');
        $zip->close();

        try {
            $this->expectException(\RuntimeException::class);
            SiteLayouts::installZip($zipPath);
        } finally {
            @unlink($zipPath);
        }
    }
}
