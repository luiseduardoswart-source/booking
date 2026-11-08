<?php

namespace App\Filament\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;

/**
 * The menu on the left in the order the logged-in user dragged it into.
 * Filament builds the menu as usual (with all permissions); then we put the
 * items in the saved order. Anything not in it (for example a screen added
 * later) goes at the bottom in the default order. Replaces Filament's
 * NavigationManager in the container.
 */
class PersonalOrder extends NavigationManager
{
    /**
     * @return array<NavigationGroup>
     */
    public function get(): array
    {
        $groups = parent::get();
        $order = array_flip(array_values(auth()->user()?->menu_order ?? []));

        if ($order === []) {
            return $groups;
        }

        foreach ($groups as $group) {
            $items = collect($group->getItems())->values();
            $atTheEnd = count($order);

            $group->items($items
                ->sortBy(fn (NavigationItem $item, int $index) => [$order[self::key($item)] ?? $atTheEnd, $index])
                ->values()
                ->all());
        }

        return $groups;
    }

    /** We recognise a menu item by the path of its link, regardless of domain and language. */
    public static function key(NavigationItem $item): string
    {
        return self::path((string) $item->getUrl());
    }

    public static function path(string $url): string
    {
        return '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
