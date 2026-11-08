<?php

namespace App\Support;

/**
 * The amenity icons, also selectable for an accommodation's highlights.
 * The drawing elements are in resources/icons/amenities.php;
 * <x-site-icon> draws them, like the site's other icons.
 */
class AmenityIcons
{
    /** The attributes of every icon SVG; the inside is drawn on 24x24 with lines. */
    public const FEATURES = 'xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';

    /** @return array<string, string> key => inside of the SVG */
    public static function all(): array
    {
        return once(fn () => require resource_path('icons/amenities.php'));
    }

    public static function inside(string $key): ?string
    {
        return static::all()[$key] ?? null;
    }

    /** The icon with a name next to it, as HTML for a select list in the admin. */
    public static function option(string $key, string $label): string
    {
        return '<span style="display:inline-flex;align-items:center;gap:.5rem">'
            .'<svg style="width:1.25rem;height:1.25rem;flex:none" '.self::FEATURES.'>'.(static::inside($key) ?? '').'</svg>'
            .e($label).'</span>';
    }
}
