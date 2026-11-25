<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Backups as BackupsPage;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Support\Backups\Backups;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use ZipArchive;

/**
 * Backups: every night (and with a button) a zip with the database and the
 * uploaded files, kept for a number of days; only the owner sees and
 * downloads them.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        // Own folders for the uploads and the backups: the real ones stay untouched.
        $this->sandbox = sys_get_temp_dir().'/backup-test-'.uniqid();
        File::ensureDirectoryExists($this->sandbox.'/public/storage/1');
        File::ensureDirectoryExists($this->sandbox.'/storage/app/private/signatures');
        File::ensureDirectoryExists($this->sandbox.'/storage/app/private/livewire-tmp');
        File::put($this->sandbox.'/public/storage/1/beach.jpg', 'photo');
        File::put($this->sandbox.'/storage/app/private/signatures/BK-1.png', 'signature');
        File::put($this->sandbox.'/storage/app/private/livewire-tmp/half-uploaded.tmp', 'tmp');
        $this->app->usePublicPath($this->sandbox.'/public');
        $this->app->useStoragePath($this->sandbox.'/storage');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function booking(string $guestName): Booking
    {
        $property = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        return Booking::create([
            'booking_number' => 'BK-BACKUP-1', 'property_id' => $property->id, 'status' => BookingStatus::Confirmed, 'payment_status' => 'paid',
            'guest_name' => $guestName, 'guest_email' => 'guest@example.com',
            'arrival_date' => today()->addDays(10)->toDateString(), 'departure_date' => today()->addDays(13)->toDateString(), 'guests' => 2,
            'total_cents' => 30000, 'down_payment_cents' => 30000, 'price_snapshot' => ['lines' => []],
        ]);
    }

    /** @return array<int, string> */
    private function entries(string $zipPath): array
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $zip->close();

        return $names;
    }

    private function sql(string $zipPath): string
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $sql = $zip->getFromName('database.sql');
        $zip->close();

        return $sql;
    }

    public function test_a_backup_holds_the_database_and_the_uploaded_files(): void
    {
        $this->booking('Jan de Vries');

        $name = Backups::run();
        $path = Backups::path($name);

        $this->assertNotNull($path);
        $entries = $this->entries($path);
        $this->assertContains('database.sql', $entries);
        $this->assertContains('README.txt', $entries);
        $this->assertContains('files/public/1/beach.jpg', $entries);
        $this->assertContains('files/private/signatures/BK-1.png', $entries);
        // Half-finished uploads and the backups themselves are no part of it.
        $this->assertEmpty(array_filter($entries, fn (string $entry) => str_contains($entry, 'livewire-tmp') || str_contains($entry, 'backups/')));

        $sql = $this->sql($path);
        $this->assertStringContainsString('CREATE TABLE `bookings`', $sql);
        $this->assertStringContainsString("'Jan de Vries'", $sql);
        // Sessions rebuild themselves: only their structure.
        $this->assertStringContainsString('CREATE TABLE `sessions`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `sessions`', $sql);

        $this->assertSame(1, Activity::query()->where('event', 'backup_made')->count());
    }

    public function test_the_database_part_puts_back_exactly_what_was_there(): void
    {
        // Quotes, a backslash, a new line and an emoji: all must survive.
        $booking = $this->booking("O'Brien \\ \"de\" Vries\nRegel 2 😀");

        $sql = $this->sql(Backups::path(Backups::run()));

        // Put the rows of the bookings table back into a temporary copy
        // (no other database may be made here, and this one stays as it is).
        preg_match('/INSERT INTO `bookings` VALUES\n.*?;\n(?=INSERT INTO `bookings`|\n)/s', $sql, $insert);
        $this->assertNotEmpty($insert);
        DB::statement('CREATE TEMPORARY TABLE `bookings_restored` LIKE `bookings`');
        DB::unprepared(str_replace('INSERT INTO `bookings` VALUES', 'INSERT INTO `bookings_restored` VALUES', $insert[0]));

        $original = (array) DB::table('bookings')->where('id', $booking->id)->first();
        $restored = (array) DB::table('bookings_restored')->where('id', $booking->id)->first();
        $this->assertSame($original, $restored);
    }

    public function test_without_files_only_the_database_is_backed_up(): void
    {
        Setting::set('backups', 'include_files', '0');

        $entries = $this->entries(Backups::path(Backups::run()));

        $this->assertSame(['database.sql', 'README.txt'], $entries);
    }

    public function test_old_backups_are_removed_but_the_newest_always_stays(): void
    {
        Setting::set('backups', 'keep_days', '7');
        File::ensureDirectoryExists(Backups::directory());
        $old = 'backup-'.now()->subDays(10)->format('Y-m-d-His').'.zip';
        $recent = 'backup-'.now()->subDays(2)->format('Y-m-d-His').'.zip';
        File::put(Backups::directory().'/'.$old, 'x');
        File::put(Backups::directory().'/'.$recent, 'x');
        File::put(Backups::directory().'/notes.txt', 'not a backup');

        $this->assertSame(1, Backups::prune());
        $this->assertSame([$recent], array_keys(Backups::all()));
        $this->assertFileExists(Backups::directory().'/notes.txt');

        // Even when every backup is too old, the newest is kept.
        File::delete(Backups::directory().'/'.$recent);
        File::put(Backups::directory().'/'.$old, 'x');
        $this->assertSame(0, Backups::prune());
        $this->assertSame([$old], array_keys(Backups::all()));
    }

    public function test_only_the_owner_downloads_a_backup_and_only_a_backup(): void
    {
        $owner = $this->owner();
        $name = Backups::run();
        File::put($this->sandbox.'/storage/app/private/backups/secret.txt', 'not for you');
        $staff = User::factory()->create(['role' => UserRole::Staff, 'permissions' => null]);

        $this->get(route('admin.backups.download', ['name' => $name]))->assertStatus(401);
        $this->actingAs($staff)->get(route('admin.backups.download', ['name' => $name]))->assertForbidden();

        $this->actingAs($owner)->get(route('admin.backups.download', ['name' => $name]))
            ->assertOk()
            ->assertDownload($name);
        $this->assertSame(1, Activity::query()->where('event', 'backup_downloaded')->count());

        foreach (['secret.txt', 'backup-2026-01-01-000000.zip', '..', '.env'] as $other) {
            $this->actingAs($owner)->get('/admin-backups/'.$other)->assertNotFound();
        }
        $this->actingAs($owner)->get('/admin-backups/..%2F..%2F.env')->assertNotFound();
    }

    public function test_only_the_owner_uses_the_backups_page(): void
    {
        $owner = $this->owner();

        foreach ([UserRole::Staff, UserRole::Host] as $role) {
            $other = User::factory()->create(['role' => $role, 'permissions' => ['bookings' => \App\Support\Access::ACTIONS]]);
            $this->actingAs($other)->get(BackupsPage::getUrl())->assertForbidden();
        }

        $page = Livewire::actingAs($owner)->test(BackupsPage::class)
            ->assertOk()
            ->assertSee(__('No backups yet'))
            ->callAction('make_backup')
            ->assertNotified(__('Backup made'));

        $name = array_key_first(Backups::all());
        $this->assertNotNull($name);

        $page->callAction('backup_settings', ['keep_days' => 14, 'include_files' => false])->assertHasNoActionErrors();
        $this->assertSame(14, Backups::keepDays());
        $this->assertFalse(Backups::includesFiles());

        $page->callAction(TestAction::make('delete')->table($name));
        $this->assertSame([], Backups::all());
        $this->assertSame(1, Activity::query()->where('event', 'backup_deleted')->count());
    }

    public function test_a_failed_backup_is_shown_on_the_page(): void
    {
        Setting::set('backups', 'last_failure', now()->toIso8601String());

        Livewire::actingAs($this->owner())->test(BackupsPage::class)
            ->assertSee(__('Make a backup now to see the reason. The backups below are still there.'));
    }
}
