<?php

namespace App\Support;

use App\Mail\ErrorDigestMail;
use App\Models\ChannelConnection;
use App\Models\ErrorReport;
use App\Models\Setting;
use App\Support\Backups\Backups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Errors the website runs into, without an outside service: every error that
 * is logged (an exception that is reported, or Log::error) is kept here once
 * per kind, with how often it happened. Once a day the owner gets one email
 * with what went wrong since the previous email: these errors, jobs that
 * failed (emails that could not be sent), calendars that do not sync and a
 * failed backup. Nothing new, no email.
 */
final class ErrorReports
{
    /** The levels that count as an error. */
    public const LEVELS = ['error', 'critical', 'alert', 'emergency'];

    public const KEEP_DAYS = 30;

    private const MESSAGE_LENGTH = 1000;

    /** True while an error is being written: an error in writing it is never written itself. */
    private static bool $recording = false;

    public static function record(string $level, string $message, ?Throwable $exception = null): void
    {
        if (self::$recording || ! in_array($level, self::LEVELS, true)) {
            return;
        }

        self::$recording = true;

        try {
            $location = $exception === null ? null : self::location($exception);
            $text = Str::limit($exception === null ? $message : class_basename($exception).': '.$exception->getMessage(), self::MESSAGE_LENGTH);
            // The same place and kind is the same error; without a place,
            // the message with its numbers left out.
            $fingerprint = sha1($level.'|'.($exception === null ? preg_replace('/\d+/', '#', $message) : $exception::class.'|'.$location));
            $now = now();

            $updated = ErrorReport::query()->where('fingerprint', $fingerprint)->update([
                'message' => $text,
                'last_seen_at' => $now,
                'occurrences' => DB::raw('occurrences + 1'),
            ]);

            if ($updated === 0) {
                ErrorReport::query()->create([
                    'fingerprint' => $fingerprint, 'level' => $level, 'message' => $text, 'location' => $location,
                    'first_seen_at' => $now, 'last_seen_at' => $now,
                ]);
            }
        } catch (Throwable) {
            // No database (yet), or it is the database itself that fails: the log file still has it.
        } finally {
            self::$recording = false;
        }
    }

    /**
     * Mail the owner what went wrong since the previous email. Returns
     * whether an email was sent.
     */
    public static function sendDigest(): bool
    {
        $since = self::lastDigest();
        $until = now();
        $digest = self::collect($since);

        Setting::set('errors', 'last_digest_at', $until->toIso8601String());
        ErrorReport::query()->where('last_seen_at', '<', $until->copy()->subDays(self::KEEP_DAYS))->delete();

        $owner = AdminNotifications::owner();

        if ($owner === null || $digest['empty']) {
            return false;
        }

        Mail::to($owner)->send(new ErrorDigestMail($digest, $since));

        return true;
    }

    /**
     * @return array{reports: \Illuminate\Support\Collection<int, ErrorReport>, failed_jobs: int, calendars: \Illuminate\Support\Collection<int, ChannelConnection>, backup_failed: bool, empty: bool}
     */
    public static function collect(CarbonImmutable $since): array
    {
        $errors = ErrorReport::query()->where('last_seen_at', '>', $since)->orderByDesc('last_seen_at')->limit(50)->get();

        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '>', $since)->count()
            : 0;

        // A calendar that does not sync stays in the email until it syncs again.
        $calendars = ChannelConnection::query()
            ->with('property:id,name')
            ->where('active', true)
            ->whereNotNull('last_error')
            ->get();

        $backupFailed = Backups::lastFailure()?->greaterThan($since) ?? false;

        return [
            'reports' => $errors,
            'failed_jobs' => $failedJobs,
            'calendars' => $calendars,
            'backup_failed' => $backupFailed,
            'empty' => $errors->isEmpty() && $failedJobs === 0 && $calendars->isEmpty() && ! $backupFailed,
        ];
    }

    /** The previous email; the first time, the last day. */
    private static function lastDigest(): CarbonImmutable
    {
        $moment = Setting::get('errors', 'last_digest_at');

        return filled($moment) ? CarbonImmutable::parse($moment) : CarbonImmutable::now()->subDay();
    }

    /** The file and line inside the website where it went wrong, without the server's folders. */
    private static function location(Throwable $exception): string
    {
        $frame = collect([['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()])
            ->first(fn (array $frame): bool => isset($frame['file']) && ! str_contains($frame['file'], DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR))
            ?? ['file' => $exception->getFile(), 'line' => $exception->getLine()];

        return Str::after(str_replace('\\', '/', $frame['file']), str_replace('\\', '/', base_path()).'/').':'.($frame['line'] ?? 0);
    }
}
