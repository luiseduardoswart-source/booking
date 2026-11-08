<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Widgets\FinishInstallation;
use App\Models\Setting;
use App\Models\User;
use App\Support\DatabaseUpdater;
use App\Support\ScheduledTasks;
use App\Support\Access;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Updating with a new zip package: if new migrations are waiting, an admin
 * runs them with one button on the dashboard, without SSH.
 */
class DatabaseUpdateTest extends TestCase
{
    use RefreshDatabase;

    /** A migration that only updates data and can safely run again. */
    private const NEW = '2026_11_07_120000_dpgo_gone';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function newVersion(): void
    {
        DB::table('migrations')->where('migration', self::NEW)->delete();
    }

    private function administrator(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_a_updated_database_asks_nothing(): void
    {
        $this->assertSame(0, DatabaseUpdater::outstanding());
        $this->assertNotContains('database', array_column(FinishInstallation::steps(), 'id'));
    }

    public function test_after_a_new_version_is_the_button_on_the_dashboard(): void
    {
        // The rest is already done: then it is only about updating.
        ScheduledTasks::tick();
        Setting::set('mail', 'host', 'smtp.example.nl');
        $this->newVersion();

        $this->assertSame(1, DatabaseUpdater::outstanding());

        $this->actingAs($this->administrator())
            ->get('/admin')
            ->assertOk()
            ->assertSee(__('Update the website'))
            ->assertSee(__('Update database'))
            ->assertSee('wire:click="updateDatabase"', false)
            ->assertDontSee(__('Finish the installation'));
    }

    public function test_the_button_works_the_database_at(): void
    {
        $this->newVersion();

        Livewire::actingAs($this->administrator())
            ->test(FinishInstallation::class)
            ->call('updateDatabase')
            ->assertOk();

        Notification::assertNotified(__('The database has been updated'));
        $this->assertSame(0, DatabaseUpdater::outstanding());
        $this->assertTrue(DB::table('migrations')->where('migration', self::NEW)->exists());
    }

    public function test_update_set_on_the_command_line_no_time_limit(): void
    {
        // Without a limit (artisan, the tests) updating must not set one:
        // otherwise the whole process stops after 300 seconds.
        $this->assertSame('0', ini_get('max_execution_time'));

        DatabaseUpdater::run();

        $this->assertSame('0', ini_get('max_execution_time'));
    }

    public function test_only_a_administrator_can_the_database_update(): void
    {
        $this->newVersion();
        $staff = User::factory()->create(['role' => UserRole::Staff, 'permissions' => ['accommodations' => Access::ACTIONS]]);

        $this->actingAs($staff);

        try {
            (new FinishInstallation)->updateDatabase();
            $this->fail('A staff member may not update the database.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(1, DatabaseUpdater::outstanding());
    }
}
