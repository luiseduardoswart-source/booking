<?php

namespace App\Support;

use App\Models\ListingEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * The visit figures behind the Statistics page and the AI connection:
 * page views, visitors, price requests and bookings over the last days,
 * for all accommodations or one. One place, so both show the same numbers.
 */
final class ListingStatistics
{
    public const DAYS = 30;

    /**
     * Events of the given type(s) in the last $days days (today included),
     * of one accommodation only when given. Columns are qualified, so the
     * query can be joined.
     *
     * @param  string|list<string>  $types
     * @return Builder<ListingEvent>
     */
    public static function events(string|array $types, ?int $propertyId = null, int $days = self::DAYS): Builder
    {
        return ListingEvent::query()
            ->whereIn('listing_events.type', (array) $types)
            ->where('listing_events.created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->when($propertyId, fn (Builder $query, int $id) => $query->where('listing_events.property_id', $id));
    }

    /**
     * @return array{views: int, visitors: int, quotes: int, bookings: int, conversion: float}
     */
    public static function summary(?int $propertyId = null, int $days = self::DAYS): array
    {
        $views = self::events('page_view', $propertyId, $days)->count();
        $bookings = self::events('booking', $propertyId, $days)->count();

        return [
            'views' => $views,
            'visitors' => self::events('page_view', $propertyId, $days)->distinct('session_id')->count('session_id'),
            'quotes' => self::events('quote', $propertyId, $days)->count(),
            'bookings' => $bookings,
            'conversion' => $views > 0 ? round($bookings / $views * 100, 1) : 0.0,
        ];
    }
}
