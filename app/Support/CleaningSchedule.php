<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\CalendarBlockType;
use App\Mail\CleaningScheduleMail;
use App\Models\Booking;
use App\Models\CalendarBlock;
use App\Models\Cleaner;
use App\Models\ChannelConnection;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The changeovers of accommodations: every departure in a period, with the
 * next arrival, so a cleaner knows when to clean and by when it must be
 * ready. Stays are the confirmed bookings of the site and the bookings that
 * come in from Airbnb, Booking.com and the like (the synced calendar).
 *
 * Only what cleaning needs: the number of guests and pets, never a name.
 */
final class CleaningSchedule
{
    /** Airbnb also exports dates the host blocked; those are no stay. */
    private const AIRBNB_NOT_A_STAY = 'not available';

    /**
     * Mail a cleaner the changeovers of the coming days (7, or 14 for weekly).
     * False when there are none (no email) or it could not be sent.
     */
    public static function mail(Cleaner $cleaner): bool
    {
        $from = today()->toImmutable();
        $changeovers = self::changeovers($cleaner->properties()->orderBy('name')->get(), $from, $from->addDays($cleaner->daysAhead() - 1));

        if ($changeovers->isEmpty()) {
            return false;
        }

        try {
            Mail::to($cleaner->email)->send(new CleaningScheduleMail($cleaner, $changeovers));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * @param  iterable<Property>  $properties
     * @return Collection<int, array{property: Property, date: CarbonImmutable, out: array, next: array|null, same_day: bool}>
     */
    public static function changeovers(iterable $properties, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        $changeovers = collect();

        foreach ($properties as $property) {
            $stays = self::stays($property, $from, $until);

            foreach ($stays as $stay) {
                if ($stay['departure']->lessThan($from) || $stay['departure']->greaterThan($until)) {
                    continue;
                }

                $next = $stays->first(fn (array $other): bool => $other['arrival']->greaterThanOrEqualTo($stay['departure']));

                $changeovers->push([
                    'property' => $property,
                    'date' => $stay['departure'],
                    'out' => $stay,
                    'next' => $next,
                    'same_day' => $next !== null && $next['arrival']->equalTo($stay['departure']),
                ]);
            }
        }

        return $changeovers->sortBy(fn (array $changeover): string => $changeover['date']->toDateString().'|'.$changeover['property']->name)->values();
    }

    /**
     * The stays of an accommodation that end in the period, and the ones
     * after them (up to two months on) to find the next arrival.
     *
     * @return Collection<int, array{arrival: CarbonImmutable, departure: CarbonImmutable, guests: int|null, pets: int|null, source: string}>
     */
    private static function stays(Property $property, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        $horizon = $until->addMonths(2);

        $bookings = Booking::query()
            ->where('property_id', $property->id)
            ->whereIn('status', BookingStatus::confirmedValues())
            ->where('departure_date', '>=', $from)
            ->where('arrival_date', '<=', $horizon)
            ->get(['arrival_date', 'departure_date', 'guests', 'infants', 'pets'])
            ->map(fn (Booking $booking): array => [
                'arrival' => $booking->arrival_date,
                'departure' => $booking->departure_date,
                'guests' => $booking->guests + (int) $booking->infants,
                'pets' => (int) $booking->pets,
                'source' => __('Website'),
            ]);

        // Named after the calendar connection the owner made (Airbnb, Booking.com...).
        $platforms = ChannelConnection::query()->where('property_id', $property->id)->get()
            ->mapWithKeys(fn (ChannelConnection $connection): array => [$connection->sourceKey() => $connection->name ?: Str::headline((string) $connection->provider)]);

        // A synced block holds nights: the departure is the day after its last night.
        $synced = CalendarBlock::query()
            ->where('property_id', $property->id)
            ->where('type', CalendarBlockType::External->value)
            ->where('end_date', '>=', $from->subDay())
            ->where('start_date', '<=', $horizon)
            ->get()
            ->reject(fn (CalendarBlock $block): bool => Str::startsWith((string) $block->source, 'airbnb-')
                && Str::contains(Str::lower((string) $block->note), self::AIRBNB_NOT_A_STAY))
            ->map(fn (CalendarBlock $block): array => [
                'arrival' => CarbonImmutable::parse($block->start_date)->startOfDay(),
                'departure' => CarbonImmutable::parse($block->end_date)->startOfDay()->addDay(),
                'guests' => null,
                'pets' => null,
                'source' => $platforms[$block->source] ?? __('Other platform'),
            ]);

        return $bookings->concat($synced)->sortBy(fn (array $stay): int => $stay['arrival']->timestamp)->values();
    }
}
