<?php

namespace App\Filament\Forms\Components;

use App\Models\Amenity;
use App\Support\AmenityIcons;
use Filament\Forms\Components\Select;

/**
 * Select for the icon of an amenity: with the icon itself in the list
 * and search by name. Empty = recognised automatically by name (see Amenity::iconName()).
 */
class IconPicker extends Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Icon'))
            ->options(fn (): array => Amenity::iconChoices())
            ->allowHtml()
            ->searchable()
            // Filament shows 50 by default; this way all of them can be browsed.
            ->optionsLimit(fn (): int => count(AmenityIcons::all()))
            // Search on the name, not in the icon's HTML.
            ->getSearchResultsUsing(fn (string $search): array => array_intersect_key(
                Amenity::iconChoices(),
                array_filter(Amenity::iconOptions(), fn (string $label) => mb_stripos($label, trim($search)) !== false),
            ))
            ->placeholder(__('Detect automatically by name'));
    }
}
