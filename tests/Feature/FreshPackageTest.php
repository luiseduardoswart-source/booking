<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A freshly unpacked zip package: the .env comes from .env.example and points to
 * a SQLite file that does not exist yet. That is "not yet installed",
 * so the wizard must open instead of redirecting to the admin.
 *
 * Deliberately without RefreshDatabase: here there is no database yet.
 */
class FreshPackageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'database.connections.fresh' => [
                'driver' => 'sqlite',
                'database' => storage_path('framework/testing/exists-not-'.uniqid().'.sqlite'),
                'prefix' => '',
            ],
            'database.default' => 'fresh',
        ]);
    }

    public function test_the_wizard_opens(): void
    {
        $this->get('/install/code')->assertOk()->assertSee(\App\Support\InstallCode::FILE);
    }

    public function test_the_website_sends_to_the_wizard(): void
    {
        $this->get('/')->assertRedirect(route('install.database'));
    }
}
