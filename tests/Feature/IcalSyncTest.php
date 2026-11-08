<?php

namespace Tests\Feature;

use App\Channels\IcalParser;
use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class IcalSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeProperty(): Property
    {
        return Property::create([
            'name' => 'Testhuis',
            'slug' => 'testhuis',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);
    }

    private const SAMPLE_ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Airbnb Inc//EN\r\n"
        ."BEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20260401\r\nDTEND;VALUE=DATE:20260404\r\n"
        ."UID:airbnb-abc123\r\nSUMMARY:Reserved\r\nEND:VEVENT\r\n"
        ."BEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260410T140000Z\r\nDTEND:20260412T100000Z\r\n"
        ."UID:airbnb-def456\r\nSUMMARY:Airbnb (Not available)\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    public function test_parser_reads_date_and_datetime_events(): void
    {
        $entries = (new IcalParser)->parse(self::SAMPLE_ICS);

        $this->assertCount(2, $entries);

        // DTEND is exclusive: 1 through 4 April becomes nights 1 through 3 April.
        $this->assertSame('airbnb-abc123', $entries[0]['external_uid']);
        $this->assertSame('2026-04-01', $entries[0]['start_date']->toDateString());
        $this->assertSame('2026-04-03', $entries[0]['end_date']->toDateString());

        $this->assertSame('2026-04-10', $entries[1]['start_date']->toDateString());
        $this->assertSame('2026-04-11', $entries[1]['end_date']->toDateString());
    }

    public function test_sync_command_blocks_external_bookings(): void
    {
        $property = $this->makeProperty();
        $property->channelConnections()->create([
            'provider' => 'ical',
            'name' => 'Airbnb',
            'url' => 'https://airbnb.example/calendar.ics',
        ]);

        Http::fake(['airbnb.example/*' => Http::response(self::SAMPLE_ICS)]);

        $this->artisan('channels:sync')->assertSuccessful();

        $this->assertSame(2, $property->calendarBlocks()->count());

        $availability = new AvailabilityEngine;
        $this->assertFalse($availability->isAvailable(
            $property,
            CarbonImmutable::parse('2026-04-01'),
            CarbonImmutable::parse('2026-04-04'),
        ));

        $connection = $property->channelConnections()->first();
        $this->assertNotNull($connection->last_synced_at);
        $this->assertNull($connection->last_error);
    }

    public function test_sync_error_is_recorded_without_crash(): void
    {
        $property = $this->makeProperty();
        $property->channelConnections()->create([
            'provider' => 'ical',
            'name' => 'Airbnb',
            'url' => 'https://airbnb.example/calendar.ics',
        ]);

        Http::fake(['airbnb.example/*' => Http::response('Server Error', 500)]);

        $this->artisan('channels:sync')->assertSuccessful();

        $this->assertNotNull($property->channelConnections()->first()->last_error);
        $this->assertSame(0, $property->calendarBlocks()->count());
    }

    public function test_conflict_between_own_booking_and_external_booking_sends_alarm(): void
    {
        $property = $this->makeProperty();
        $property->channelConnections()->create([
            'provider' => 'ical',
            'name' => 'Airbnb',
            'url' => 'https://airbnb.example/calendar.ics',
        ]);

        // Own booking 1 through 4 April, the same dates as the external event.
        app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse('2026-04-01'),
            CarbonImmutable::parse('2026-04-04'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        Http::fake(['airbnb.example/*' => Http::response(self::SAMPLE_ICS)]);

        $this->artisan('channels:sync')->assertSuccessful();

        Mail::assertSent(\App\Mail\CalendarConflictMail::class, fn ($mail) => count($mail->conflicts) === 1);
    }

    public function test_export_feed_contains_bookings_and_blocks(): void
    {
        $property = $this->makeProperty();

        app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-05-04'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        app(\App\Engines\CalendarEngine::class)->block(
            $property,
            CarbonImmutable::parse('2026-06-10'),
            CarbonImmutable::parse('2026-06-12'),
        );

        $response = $this->get("/ical/{$property->id}/{$property->icalToken()}.ics");

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->assertSee('DTSTART;VALUE=DATE:20260501', false)
            ->assertSee('DTEND;VALUE=DATE:20260504', false)
            ->assertSee('DTSTART;VALUE=DATE:20260610', false)
            ->assertSee('DTEND;VALUE=DATE:20260613', false); // inclusive 12 June → exclusive 13 June
    }

    public function test_export_with_error_token_gives_404(): void
    {
        $property = $this->makeProperty();
        $property->icalToken();

        $this->get("/ical/{$property->id}/wrong-token.ics")->assertNotFound();
    }

    public function test_imported_blocks_come_not_back_in_the_export(): void
    {
        $property = $this->makeProperty();
        $connection = $property->channelConnections()->create([
            'provider' => 'ical',
            'name' => 'Airbnb',
            'url' => 'https://airbnb.example/calendar.ics',
        ]);

        Http::fake(['airbnb.example/*' => Http::response(self::SAMPLE_ICS)]);
        $this->artisan('channels:sync')->assertSuccessful();

        $this->get("/ical/{$property->id}/{$property->icalToken()}.ics")
            ->assertOk()
            ->assertDontSee('20260401', false); // external event is not echoed back
    }
}
