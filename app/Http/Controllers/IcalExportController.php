<?php

namespace App\Http\Controllers;

use App\Enums\CalendarBlockType;
use App\Models\Booking;
use App\Models\Property;
use Illuminate\Http\Response;

/**
 * Secret iCal feed per accommodation. Paste this URL into Airbnb or
 * Booking.com so external platforms pick up our occupancy.
 */
class IcalExportController extends Controller
{
    public function __invoke(Property $property, string $token): Response
    {
        abort_unless(
            $property->ical_token !== null && hash_equals($property->ical_token, $token),
            404,
        );

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.config('app.name').'//PMS//NL',
            'CALSCALE:GREGORIAN',
        ];

        // Our own bookings (active statuses). DTEND is exclusive, and so is
        // our departure date.
        $property->bookings()
            ->whereNotIn('status', Booking::INACTIVE_STATUSES)
            ->orderBy('arrival_date')
            ->each(function (Booking $booking) use (&$lines) {
                array_push(
                    $lines,
                    'BEGIN:VEVENT',
                    "UID:booking-{$booking->id}@".parse_url(config('app.url'), PHP_URL_HOST),
                    'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
                    'DTSTART;VALUE=DATE:'.$booking->arrival_date->format('Ymd'),
                    'DTEND;VALUE=DATE:'.$booking->departure_date->format('Ymd'),
                    'SUMMARY:Booked',
                    'END:VEVENT',
                );
            });

        // Manual and maintenance blocks. Externally imported blocks are not
        // exported back (prevents echo loops between platforms).
        $property->calendarBlocks()
            ->where('type', '!=', CalendarBlockType::External->value)
            ->orderBy('start_date')
            ->each(function ($block) use (&$lines) {
                array_push(
                    $lines,
                    'BEGIN:VEVENT',
                    "UID:block-{$block->id}@".parse_url(config('app.url'), PHP_URL_HOST),
                    'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
                    'DTSTART;VALUE=DATE:'.$block->start_date->format('Ymd'),
                    'DTEND;VALUE=DATE:'.$block->end_date->addDay()->format('Ymd'), // inclusive → exclusive
                    'SUMMARY:Not available',
                    'END:VEVENT',
                );
            });

        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$property->slug.'.ics"',
        ]);
    }
}
