<?php

namespace App\Filament\Statistics\Concerns;

use App\Models\ListingEvent;
use App\Support\ListingStatistics;
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

    /** The chosen accommodation, or null for all of them. */
    protected function accommodationId(): ?int
    {
        $id = $this->pageFilters['property_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Events of the given type(s) in the last 30 days, of the chosen
     * accommodation only when one is chosen (see ListingStatistics).
     *
     * @param  string|list<string>  $types
     * @return Builder<ListingEvent>
     */
    protected function events(string|array $types): Builder
    {
        return ListingStatistics::events($types, $this->accommodationId());
    }
}
