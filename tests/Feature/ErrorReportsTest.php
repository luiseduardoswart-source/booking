<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\ErrorDigestMail;
use App\Models\ChannelConnection;
use App\Models\ErrorReport;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Without an outside service: the website keeps the errors it runs into and
 * mails the owner once a day what went wrong, and nothing when all is well.
 */
class ErrorReportsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Administrator, 'email' => 'owner@example.com', 'locale' => 'nl']);
        Setting::set('company', 'email', 'owner@example.com');

        return $owner;
    }

    public function test_an_error_is_kept_once_per_kind_with_how_often_it_happened(): void
    {
        foreach ([1, 2, 3] as $attempt) {
            report(new RuntimeException("The payment provider did not answer (attempt {$attempt})"));
        }
        Log::error('Mail to guest 123 failed');
        Log::error('Mail to guest 456 failed');
        Log::warning('Only a warning');

        $this->assertSame(2, ErrorReport::count());
        $exception = ErrorReport::query()->where('message', 'like', 'RuntimeException%')->sole();
        $this->assertSame(3, $exception->occurrences);
        $this->assertSame('RuntimeException: The payment provider did not answer (attempt 3)', $exception->message);
        $this->assertStringStartsWith('tests/Feature/ErrorReportsTest.php:', $exception->location);
        $this->assertSame(2, ErrorReport::query()->where('message', 'like', 'Mail to guest%')->sole()->occurrences);
    }

    public function test_the_owner_gets_one_email_with_what_went_wrong(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $property = Property::create(['name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000]);
        $calendar = ChannelConnection::create(['property_id' => $property->id, 'provider' => 'airbnb', 'name' => 'Airbnb', 'url' => 'https://example.com/a.ics', 'active' => true, 'last_error' => 'The address did not return a calendar.']);
        DB::table('failed_jobs')->insert(['uuid' => 'a1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        Setting::set('backups', 'last_failure', now()->toIso8601String());
        report(new RuntimeException('The payment provider did not answer'));

        $this->artisan('errors:report')->assertSuccessful();

        Mail::assertSent(ErrorDigestMail::class, function (ErrorDigestMail $mail) use ($owner): bool {
            $html = $mail->render();

            return $mail->hasTo($owner->email)
                && $mail->locale === 'nl'
                && str_contains($html, 'The payment provider did not answer')
                && str_contains($html, 'The address did not return a calendar.')
                && str_contains($html, __('The nightly backup failed.', [], 'nl'))
                && str_contains($html, 'Beach house');
        });

        // The next day: the calendar syncs again and nothing else happened.
        Mail::fake();
        $calendar->update(['last_error' => null]);
        $this->travel(1)->days();
        $this->artisan('errors:report')->assertSuccessful();
        Mail::assertNothingSent();

        // A new error the day after: only that one.
        $this->travel(1)->days();
        Log::error('Something new went wrong');
        $this->artisan('errors:report')->assertSuccessful();
        Mail::assertSent(ErrorDigestMail::class, fn (ErrorDigestMail $mail) => $mail->digest['reports']->pluck('message')->all() === ['Something new went wrong']
            && $mail->digest['failed_jobs'] === 0 && ! $mail->digest['backup_failed']);
    }

    public function test_errors_older_than_a_month_are_removed(): void
    {
        Log::error('Long ago');
        $this->travel(31)->days();
        Log::error('Recently');

        $this->artisan('errors:report')->assertSuccessful();

        $this->assertSame(['Recently'], ErrorReport::query()->pluck('message')->all());
    }
}
