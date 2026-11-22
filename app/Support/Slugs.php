<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Property;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The root-level addresses: accommodations and pages share that space, in
 * every language. This holds what applies to both.
 */
class Slugs
{
    /** Paths of fixed routes (see routes/web.php); a slug with such a name would never be reachable. */
    public const RESERVED = [
        'admin', 'api', 'accommodations', 'accommodation', 'payment', 'booking',
        'build', 'contact', 'ical', 'install', 'livewire', 'storage', 'language',
        'track', 'up', 'currency', 'vendor', 'webhooks', 'my-bookings',
    ];

    /** Is this address already in use, in any language? */
    public static function occupied(string $slug, ?Model $except = null): bool
    {
        // A language code is the prefix of that language's addresses (/es/...).
        if (in_array($slug, self::RESERVED, true) || in_array($slug, [...Languages::available(), ...array_keys(Languages::NAMES)], true)) {
            return true;
        }

        return collect([Property::findBySlug($slug), Page::findBySlug($slug)])
            ->filter()
            ->contains(fn (Model $model) => ! $model->is($except));
    }

    /** The slug, or with a number appended if it is already in use. */
    public static function free(string $slug, ?Model $before = null): string
    {
        $candidate = $slug;

        for ($number = 2; self::occupied($candidate, $before); $number++) {
            $candidate = "{$slug}-{$number}";
        }

        return $candidate;
    }

    /** Validation rule for a slug in a form. */
    public static function rule(?Model $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $error) use ($record) {
            if (filled($value) && self::occupied((string) $value, $record)) {
                $error(__('This address is already in use.'));
            }
        };
    }

    /** The language this request's address belongs to, if it is a translated address. */
    public static function languageOfRequest(Request $request): ?string
    {
        $slug = $request->route()?->originalParameter('property');
        $value = $request->route('property');
        $record = is_string($value) ? Page::findBySlug($value) : $value;

        return is_string($slug) && ($record instanceof Property || $record instanceof Page)
            ? $record->languageOfSlug($slug)
            : null;
    }
}
