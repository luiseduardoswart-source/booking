<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CalendarBlockType;
use App\Enums\UserRole;
use App\Filament\Resources\Cleaners\CleanerResource;
use App\Filament\Resources\Cleaners\Pages\ManageCleaners;
use App\Mail\CleaningScheduleMail;
use App\Models\Booking;
use App\Models\CalendarBlock;
use App\Models\ChannelConnection;
use App\Models\Cleaner;
use App\Models\Property;
use App\Models\User;
use App\Support\CleaningSchedule;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cleaners get the changeovers of their accommodations by email and on a
 * private page: the bookings of the site and the ones that come in from
 * Airbnb and the like, with the number of guests but never a name.
 */
class CleaningScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Property $beach;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // A Wednesday: the weekly email waits for Monday.
        $this->travelTo(CarbonImmutable::parse('2027-03-03 06:30'));
        $this->today = CarbonImmutable::today();

        $this->beach = Property::create([
            'name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home', 'max_guests' => 6, 'active' => true,
            'base_price_cents' => 10000, 'city' => 'Cartagena', 'check_out_until' => '10:00', 'check_in_from' => '15:00',
        ]);
    }

    private function booking(int $arrival, int $departure, array $extra = []): Booking
    {
        static $number = 0;
        $number++;

        return Booking::create([
            'booking_number' => "BK-CLEAN-{$number}", 'property_id' => $this->beach->id, 'status' => BookingStatus::Confirmed,
            'payment_status' => 'paid', 'guest_name' => 'Jan de Vries', 'guest_email' => 'jan@example.com',
            'arrival_date' => $this->today->addDays($arrival)->toDateString(), 'departure_date' => $this->today->addDays($departure)->toDateString(),
            'guests' => 2, 'pets' => 1, 'total_cents' => 30000, 'down_payment_cents' => 30000, 'price_snapshot' => ['lines' => []],
            ...$extra,
        ]);
    }

    /** A block from a synced calendar: $firstNight up to and including $lastNight. */
    private function synced(ChannelConnection $connection, int $firstNight, int $lastNight, string $summary): void
    {
        CalendarBlock::create([
            'property_id' => $this->beach->id, 'type' => CalendarBlockType::External,
            'start_date' => $this->today->addDays($firstNight)->toDateString(), 'end_date' => $this->today->addDays($lastNight)->toDateString(),
            'source' => $connection->sourceKey(), 'external_uid' => uniqid().'@airbnb.com', 'note' => $summary,
        ]);
    }

    private function cleaner(array $extra = []): Cleaner
    {
        $cleaner = Cleaner::create(['name' => 'Rosa', 'email' => 'rosa@example.com', 'locale' => 'en', 'frequency' => Cleaner::DAILY, 'active' => true, ...$extra]);
        $cleaner->properties()->attach($this->beach);

        return $cleaner;
    }

    private function airbnb(): ChannelConnection
    {
        return ChannelConnection::create(['property_id' => $this->beach->id, 'provider' => 'airbnb', 'name' => 'Airbnb', 'url' => 'https://example.com/a.ics', 'active' => true]);
    }

    public function test_the_changeovers_come_from_the_site_and_the_synced_calendars(): void
    {
        $airbnb = $this->airbnb();
        $this->booking(0, 3);                                         // leaves in 3 days
        $this->synced($airbnb, 3, 5, 'Reserved');                     // arrives that same day, leaves in 6 days
        $this->synced($airbnb, 9, 10, 'Airbnb (Not available)');      // dates the host blocked: no stay
        $this->booking(12, 14, ['status' => BookingStatus::New]);     // not confirmed (yet)
        $this->booking(15, 17, ['status' => BookingStatus::Cancelled]);

        $changeovers = CleaningSchedule::changeovers([$this->beach], $this->today, $this->today->addDays(13));

        $this->assertCount(2, $changeovers);
        [$first, $second] = $changeovers;
        $this->assertTrue($first['date']->equalTo($this->today->addDays(3)));
        $this->assertSame(2, $first['out']['guests']);
        $this->assertSame(1, $first['out']['pets']);
        $this->assertTrue($first['same_day']);
        $this->assertSame('Airbnb', $first['next']['source']);
        $this->assertTrue($second['date']->equalTo($this->today->addDays(6)));
        $this->assertNull($second['next']);
    }

    public function test_the_private_page_shows_the_changeovers_without_guest_names(): void
    {
        $this->booking(0, 3);
        $cleaner = $this->cleaner();

        $this->get($cleaner->scheduleUrl())
            ->assertOk()
            ->assertSee('Beach house')
            ->assertSee(__('Before :time', ['time' => '10:00']))
            ->assertSee(trans_choice('{1} 1 person|[2,*] :count people', 2))
            ->assertDontSee('Jan de Vries')
            ->assertDontSee('jan@example.com');

        // Only through the signed link, with the current version, while active.
        $this->get(route('cleaning.schedule', ['cleaner' => $cleaner->id, 'version' => 1]))->assertForbidden();
        $old = $cleaner->scheduleUrl();
        $cleaner->newLink();
        $this->get($old)->assertNotFound();
        $this->get($cleaner->scheduleUrl())->assertOk();
        $cleaner->update(['active' => false]);
        $this->get($cleaner->scheduleUrl())->assertNotFound();
    }

    public function test_the_email_goes_daily_or_on_monday_and_only_with_changeovers(): void
    {
        Mail::fake();
        $this->booking(0, 3);
        $daily = $this->cleaner();
        $weekly = $this->cleaner(['name' => 'Kees', 'email' => 'kees@example.com', 'frequency' => Cleaner::WEEKLY]);
        $nothing = Cleaner::create(['name' => 'Piet', 'email' => 'piet@example.com', 'locale' => 'en', 'frequency' => Cleaner::DAILY, 'active' => true]);

        $this->artisan('cleaning:send')->assertSuccessful();

        Mail::assertSent(CleaningScheduleMail::class, 1);
        Mail::assertSent(CleaningScheduleMail::class, function (CleaningScheduleMail $mail) use ($daily): bool {
            $html = $mail->render();

            return $mail->hasTo('rosa@example.com')
                && str_contains($html, e($daily->scheduleUrl()))
                && str_contains($html, 'Beach house')
                && ! str_contains($html, 'Jan de Vries');
        });

        // Monday: the weekly one too.
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2027-03-01 06:30'));
        $this->booking(1, 2);
        $this->artisan('cleaning:send')->assertSuccessful();
        Mail::assertSent(CleaningScheduleMail::class, fn (CleaningScheduleMail $mail) => $mail->hasTo('kees@example.com'));
        Mail::assertNotSent(CleaningScheduleMail::class, fn (CleaningScheduleMail $mail) => $mail->hasTo($nothing->email));
    }

    public function test_only_the_owner_manages_cleaners(): void
    {
        Mail::fake();
        $this->booking(0, 3);
        $owner = User::factory()->create(['role' => UserRole::Administrator]);

        foreach ([UserRole::Staff, UserRole::Host] as $role) {
            $other = User::factory()->create(['role' => $role, 'permissions' => ['bookings' => \App\Support\Access::ACTIONS]]);
            $this->actingAs($other)->get(CleanerResource::getUrl())->assertForbidden();
        }

        $page = Livewire::actingAs($owner)->test(ManageCleaners::class)
            ->callAction('create', ['name' => 'Rosa', 'email' => 'rosa@example.com', 'phone' => null, 'locale' => 'en', 'frequency' => Cleaner::DAILY, 'active' => true, 'properties' => [$this->beach->id]])
            ->assertHasNoActionErrors();

        $cleaner = Cleaner::query()->sole();
        $this->assertTrue($cleaner->properties->first()->is($this->beach));

        $page->callAction(TestAction::make('send_now')->table($cleaner));
        Mail::assertSent(CleaningScheduleMail::class, fn (CleaningScheduleMail $mail) => $mail->hasTo('rosa@example.com'));

        $page->callAction(TestAction::make('new_link')->table($cleaner));
        $this->assertSame(2, $cleaner->refresh()->link_version);
    }
}
