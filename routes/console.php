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
 *
 * Every task also runs while the site is closed (`php artisan down`): that is
 * how an owner tests before going live, and the bookings made then need their
 * emails, ID checks and payment deadlines like any other. Laravel skips
 * scheduled tasks in maintenance mode unless told otherwise.
 */
$task = fn (string $command, array $options = []): CallbackEvent => Schedule::call(fn () => Artisan::call($command, $options))
    ->name($command)
    ->withoutOverlapping()
    ->evenInMaintenanceMode();

// Heartbeat: lets the dashboard show that the cron job is running (see ScheduledTasks).
Schedule::call(fn () => ScheduledTasks::tick())->name('heartbeat')->everyMinute()->evenInMaintenanceMode();

// Scheduled guest mails: remaining payment, check-in, check-out and review request.
$task('mails:send-scheduled')->dailyAt('09:00');

// ID check after the payment: reminder before the deadline, then cancel or notify.
$task('identity:process')->dailyAt('08:45');

// Bookings made on the site that were not paid in time give their dates back.
$task('bookings:release-unpaid')->everyFiveMinutes();

// Smart lock codes of stays that no longer get in come off the lock (also a
// removal that failed at the cancellation itself).
$task('locks:sync')->hourly();

// Sync external calendars (iCal) every 30 minutes.
$task('channels:sync')->everyThirtyMinutes();

// Deposit refunds after departure (automatic or reminder to the admin).
$task('deposits:process')->dailyAt('09:30');

// Backup of the database and the uploaded files; old backups are removed.
$task('backups:make')->dailyAt('02:30');

// One email to the owner with what went wrong since yesterday (nothing new, no email).
$task('errors:report')->dailyAt('07:00');

// The changeovers of the coming days to every cleaner (daily, or on Mondays).
$task('cleaning:send')->dailyAt('06:30');

// The details of travellers no longer than each accommodation keeps them.
$task('registrations:purge')->dailyAt('03:45');

// The log keeps a year: older lines (with guest names) are removed.
// --force: live, the command otherwise asks for a confirmation nobody gives.
$task('activitylog:clean', ['--force' => true])->dailyAt('03:30');

// Pull dynamic prices (PriceLabs) daily.
$task('prices:sync')->dailyAt('05:00');

// Empty the queue (guest mails are put in it) every minute. Stops
// as soon as it is empty, and after 50 seconds at the latest, before the next minute.
// --force: the worker itself also pauses while the site is closed.
$task('queue:work', ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3, '--force' => true])->everyMinute();
