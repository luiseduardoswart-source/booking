<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Are the scheduled tasks (mails, calendars, prices) running? Every minute the
 * scheduler ticks a heartbeat (routes/console.php). If it stays away, the
 * cron job is not on and the dashboard reports which line has to go in.
 */
final class ScheduledTasks
{
    private const HEARTBEAT = 'scheduled-tasks.heartbeat';

    /** The heartbeat may stay away this long before we raise the alarm. */
    private const MARGIN_MINUTES = 10;

    public static function tick(): void
    {
        Cache::forever(self::HEARTBEAT, now()->getTimestamp());
    }

    public static function run(): bool
    {
        $last = Cache::get(self::HEARTBEAT);

        return is_int($last) && $last >= now()->subMinutes(self::MARGIN_MINUTES)->getTimestamp();
    }

    /** The command for the cron job, with the real path of this installation. */
    public static function cronCommand(): string
    {
        return 'php '.base_path('artisan').' schedule:run';
    }
}
