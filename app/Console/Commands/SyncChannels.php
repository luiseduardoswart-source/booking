<?php

namespace App\Console\Commands;

use App\Channels\IcalChannelProvider;
use App\Engines\CalendarEngine;
use App\Models\Booking;
use App\Models\ChannelConnection;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SyncChannels extends Command
{
    protected $signature = 'channels:sync';

    protected $description = 'Syncs external calendars (iCal) and reports conflicts with own bookings';

    public function handle(CalendarEngine $calendar, IcalChannelProvider $ical): int
    {
        $connections = ChannelConnection::query()
            ->where('active', true)
            ->with('property')
            ->get();

        foreach ($connections as $connection) {
            $provider = match ($connection->provider) {
                default => $ical, // new providers: add match arms
            };

            try {
                $entries = $provider->pull($connection);

                $calendar->syncExternal($connection->property, $connection->sourceKey(), $entries);

                $connection->update([
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]);

                $this->info("{$connection->name} ({$connection->property->name}): ".count($entries).' period(s).');

                $this->reportConflicts($connection);
            } catch (Throwable $e) {
                $connection->update(['last_error' => $e->getMessage()]);
                $this->error("{$connection->name}: {$e->getMessage()}");
                report($e);
            }
        }

        return self::SUCCESS;
    }

    /**
     * An external block overlaps an own active booking → mail to the
     * admin. This can happen because of the iCal delay (15-30 min).
     */
    private function reportConflicts(ChannelConnection $connection): void
    {
        $conflicts = [];

        $blocks = $connection->property->calendarBlocks()
            ->where('source', $connection->sourceKey())
            ->get();

        foreach ($blocks as $block) {
            $overlapping = $connection->property->bookings()
                ->whereNotIn('status', Booking::INACTIVE_STATUSES)
                ->whereDate('arrival_date', '<=', $block->end_date)
                ->whereDate('departure_date', '>', $block->start_date)
                ->get();

            foreach ($overlapping as $booking) {
                $conflicts[] = "Booking {$booking->booking_number} ({$booking->arrival_date->format('d-m-Y')} to "
                    ."{$booking->departure_date->format('d-m-Y')}) overlaps with an external booking from "
                    ."{$connection->name} ({$block->start_date->format('d-m-Y')} to {$block->end_date->format('d-m-Y')}).";
            }
        }

        if ($conflicts === []) {
            return;
        }

        $recipient = Setting::get('company', 'email', config('mail.from.address'));

        Mail::to($recipient)->send(new \App\Mail\CalendarConflictMail($connection->property, $conflicts));

        $this->warn(count($conflicts).' conflict(s) reported to '.$recipient);
    }
}
