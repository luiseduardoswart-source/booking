<?php

namespace App\Livewire;

use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Amenity;
use App\Models\Property;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Below the amenities picker of an accommodation: the chosen amenities in
 * the order of the page. Drag to reorder; the first ones (Amenity::ON_THE_PAGE)
 * appear on the page, the rest under "Show all amenities". Saved immediately.
 */
class AccommodationAmenities extends Component
{
    /** Sent by the picker after it saved, so this list shows the new choice. */
    public const CHANGED = 'accommodation-amenities-changed';

    public Property $property;

    #[On(self::CHANGED)]
    public function refreshList(): void
    {
        // Rendering fetches the list fresh.
    }

    /** Called by wire:sort with the dragged amenity and its new position. */
    public function move(int $amenityId, int $position): void
    {
        abort_unless(PropertyResource::canEdit($this->property), 403);

        $this->property->moveAmenity($amenityId, $position);
    }

    public function render(): View
    {
        return view('livewire.accommodation-amenities', [
            'amenities' => $this->property->unsetRelation('amenities')->amenities()->get(),
            'onThePage' => Amenity::ON_THE_PAGE,
        ]);
    }
}
