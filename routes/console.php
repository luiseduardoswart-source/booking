<?php

use App\Support\ScheduledTasks;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled tasks. On the server one cron runs `schedule:run` every minute;
 * it executes whatever is due below (see README.md, step 6).
 *
 * Every task runs in the same PHP process (Artisan::call). Schedule::command()
 * starts a separate process via proc_open, which is disabled on shared hosting:
 * every scheduled task would fail silently there.
 */
$task = fn (string $command, array $options = []): CallbackEvent => Schedule::call(fn () => Artisan::call($command, $options))
    ->name($command)
    ->withoutOverlapping();

// Heartbeat: lets the dashboard show that the cron job is running (see ScheduledTasks).
Schedule::call(fn () => ScheduledTasks::tick())->name('heartbeat')->everyMinute();

// Scheduled guest mails: remaining payment, check-in, check-out and review request.
$task('mails:send-scheduled')->dailyAt('09:00');

// ID check after the payment: reminder before the deadline, then cancel or notify.
$task('identity:process')->dailyAt('08:45');

// Bookings made on the site that were not paid in time give their dates back.
$task('bookings:release-unpaid')->everyFiveMinutes();

// Sync external calendars (iCal) every 30 minutes.
$task('channels:sync')->everyThirtyMinutes();

// Deposit refunds after departure (automatic or reminder to the admin).
$task('deposits:process')->dailyAt('09:30');

// Pull dynamic prices (PriceLabs) daily.
$task('prices:sync')->dailyAt('05:00');

// Empty the queue (guest mails are put in it) every minute. Stops
// as soon as it is empty, and after 50 seconds at the latest, before the next minute.
$task('queue:work', ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3])->everyMinute();
