<?php

namespace App\Filament\Statistics\Concerns;

use App\Models\ListingEvent;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;

/**
 * The accommodation filter at the top of the Statistics page. Every widget
 * builds its events query through events(), so that choosing an
 * accommodation narrows all figures at once; empty = all accommodations.
 */
trait FiltersByAccommodation
{
    use InteractsWithPageFilters;

    protected const DAYS = 30;

    /** The chosen accommodation, or null for all of them. */
    protected function accommodationId(): ?int
    {
        $id = $this->pageFilters['property_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Events of the given type(s) in the last 30 days, of the chosen
     * accommodation only when one is chosen. Columns are qualified, so the
     * query can be joined.
     *
     * @param  string|list<string>  $types
     * @return Builder<ListingEvent>
     */
    protected function events(string|array $types): Builder
    {
        return ListingEvent::query()
            ->whereIn('listing_events.type', (array) $types)
            ->where('listing_events.created_at', '>=', now()->subDays(self::DAYS - 1)->startOfDay())
            ->when($this->accommodationId(), fn (Builder $query, int $id) => $query->where('listing_events.property_id', $id));
    }
}
