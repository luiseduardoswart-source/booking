<?php

namespace Tests\Feature;

use App\Filament\Widgets\FinishInstallation;
use App\Support\ScheduledTasks;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\CacheBasedMaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The scheduled tasks run in the same PHP process: on shared hosting
 * proc_open is disabled, and then every task that starts a separate process fails.
 */
class ScheduledTasksTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, Event> name => task */
    private function tasks(): array
    {
        return collect(app(Schedule::class)->events())
            ->mapWithKeys(fn (Event $task) => [$task->description => $task])
            ->all();
    }

    public function test_no_single_task_start_a_separate_process(): void
    {
        foreach ($this->tasks() as $name => $task) {
            $this->assertInstanceOf(CallbackEvent::class, $task, "{$name} starts a separate process");
            $this->assertNull($task->command, "{$name} starts a separate process");
        }
    }

    public function test_all_tasks_are_on_the_right_moment_scheduled(): void
    {
        $moments = array_map(fn (Event $task) => $task->expression, $this->tasks());

        $this->assertSame([
            'heartbeat' => '* * * * *',
            'mails:send-scheduled' => '0 9 * * *',
            'identity:process' => '45 8 * * *',
            'bookings:release-unpaid' => '*/5 * * * *',
            'locks:sync' => '0 * * * *',
            'channels:sync' => '*/30 * * * *',
            'deposits:process' => '30 9 * * *',
            'prices:sync' => '0 5 * * *',
            'queue:work' => '* * * * *',
        ], $moments);
    }

    public function test_the_commands_have_english_names(): void
    {
        $commands = Artisan::all();

        foreach (['mails:send-scheduled', 'deposits:process', 'prices:sync', 'lang:translate', 'admin:disable-two-factor'] as $name) {
            $this->assertArrayHasKey($name, $commands);
        }
    }

    public function test_the_queue_task_runs_waiting_jobs_off(): void
    {
        config(['queue.default' => 'database']);
        dispatch(fn () => Cache::put('job-executed', true));
        $this->assertSame(1, DB::table('jobs')->count());

        $this->tasks()['queue:work']->run(app());

        $this->assertTrue(Cache::get('job-executed'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Closed with `php artisan down`. Kept in the cache instead of the usual
     * file, so the test never closes the local website.
     */
    private function closeTheSite(): void
    {
        $this->app->instance(MaintenanceMode::class, new CacheBasedMaintenanceMode(app('cache'), 'array', 'site-closed'));
        $this->app->maintenanceMode()->activate([]);
        $this->assertTrue($this->app->isDownForMaintenance());
    }

    public function test_with_the_site_closed_every_task_still_runs(): void
    {
        $this->closeTheSite();

        foreach ($this->tasks() as $name => $task) {
            $this->assertTrue($task->runsInMaintenanceMode(), "{$name} stops while the site is closed");
        }

        // What the cron does every minute: the waiting emails go out and the
        // dashboard hears the heartbeat. At 10:01 only those two are due.
        $this->travelTo(now()->setTime(10, 1));
        config(['queue.default' => 'database']);
        dispatch(fn () => Cache::put('job-executed', true));

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertTrue(Cache::get('job-executed'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertTrue(ScheduledTasks::run());
    }

    public function test_with_the_site_closed_the_dashboard_says_how_to_open_it(): void
    {
        $open = fn () => collect(FinishInstallation::steps())->firstWhere('title', __('Open the website for guests'));
        $this->assertNull($open());

        $this->closeTheSite();

        $this->assertSame('php '.base_path('artisan').' up', $open()['code']);
    }
}
