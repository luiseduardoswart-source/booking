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
        if (in_array($slug, self::RESERVED, true)) {
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

    /** The accommodation or page behind the current address, if there is one. */
    private static function ofAddress(string $slug): Property|Page|null
    {
        return Property::findBySlug($slug) ?? Page::findBySlug($slug);
    }

    /** The language this request's address belongs to, if it is a translated address. */
    public static function languageOfRequest(Request $request): ?string
    {
        $value = $request->route('property');
        $record = is_string($value) ? Page::findBySlug($value) : $value;

        return $record instanceof Property || $record instanceof Page
            ? $record->languageOfSlug((string) $request->segment(1))
            : null;
    }

    /**
     * The same address in another language. Anything that is not an accommodation or page
     * (home, contact) has no address of its own per language and stays the same.
     */
    public static function addressInLanguage(string $address, string $locale): string
    {
        $share = explode('/', trim((string) parse_url($address, PHP_URL_PATH), '/'), 2);
        $record = $share[0] !== '' ? self::ofAddress($share[0]) : null;

        if ($record === null) {
            return $address;
        }

        $share[0] = $record->slugFor($locale);
        $query = parse_url($address, PHP_URL_QUERY);

        return url(implode('/', $share)).($query ? "?{$query}" : '');
    }
}
