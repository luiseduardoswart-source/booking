<?php

namespace Tests\Feature;

use App\Support\Release;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * After an update with the zip package the site clears its caches once by
 * itself: extracted files often have an older date than the compiled views,
 * and then the old pages would stay.
 */
class ReleaseCacheTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/booking-release-'.uniqid();
        mkdir($this->tmp.'/views', 0777, true);
        mkdir($this->tmp.'/bootstrap/cache/filament/panels', 0777, true);
        config(['view.compiled' => $this->tmp.'/views']);
        $this->app->useBootstrapPath($this->tmp.'/bootstrap');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function refresh(): bool
    {
        return Release::refreshIfNew($this->tmp.'/VERSION', $this->tmp.'/deployed-version');
    }

    public function test_a_new_version_clears_the_old_views_and_caches_once(): void
    {
        file_put_contents($this->tmp.'/views/old.php', 'old view');
        file_put_contents($this->tmp.'/bootstrap/cache/packages.php', '<?php return [];');
        file_put_contents($this->tmp.'/bootstrap/cache/filament/panels/admin.php', '<?php return [];');

        // Without VERSION (locally, or an install with git): nothing happens.
        $this->assertFalse($this->refresh());
        $this->assertFileExists($this->tmp.'/views/old.php');

        file_put_contents($this->tmp.'/VERSION', "v1.2.0\n");
        $this->assertTrue($this->refresh());
        $this->assertFileDoesNotExist($this->tmp.'/views/old.php');
        $this->assertFileDoesNotExist($this->tmp.'/bootstrap/cache/packages.php');
        $this->assertFileDoesNotExist($this->tmp.'/bootstrap/cache/filament/panels/admin.php');
        $this->assertSame('v1.2.0', file_get_contents($this->tmp.'/deployed-version'));

        // The same version: not cleared again.
        file_put_contents($this->tmp.'/views/new.php', 'new view');
        $this->assertFalse($this->refresh());
        $this->assertFileExists($this->tmp.'/views/new.php');

        // The next version: cleared.
        file_put_contents($this->tmp.'/VERSION', "v1.3.0\n");
        $this->assertTrue($this->refresh());
        $this->assertFileDoesNotExist($this->tmp.'/views/new.php');
    }

    public function test_it_runs_on_every_request_and_the_zip_brings_the_version(): void
    {
        $this->assertStringContainsString('Release::refreshIfNew()', file_get_contents(app_path('Providers/AppServiceProvider.php')));
        $this->assertStringContainsString('> package/booking/VERSION', file_get_contents(base_path('.github/workflows/release.yml')));
        $this->assertStringContainsString('/VERSION', file_get_contents(base_path('.gitignore')));
    }
}
