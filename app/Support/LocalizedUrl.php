<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Every language has its own address, so search engines find each one. The
 * default language has no prefix (/, /beach-house, /contact); every other
 * language is under its code (/es, /es/casa-frente-al-mar, /es/contact).
 *
 * Only the pages search engines index have this: the home page, contact,
 * accommodations and pages. Booking, paying and My bookings stay on one
 * address and follow the language the visitor chose.
 */
final class LocalizedUrl
{
    /** Route names of the indexable pages; the prefixed versions carry PREFIXED in front. */
    public const INDEXABLE = ['home', 'contact', 'properties.show'];

    public const PREFIXED = 'localized.';

    /** '' for the default language, otherwise '/es'. */
    public static function prefix(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === Languages::default() ? '' : '/'.$locale;
    }

    public static function home(?string $locale = null, array $query = []): string
    {
        return self::to(self::prefix($locale) ?: '/', $query);
    }

    public static function contact(?string $locale = null): string
    {
        return self::to(self::prefix($locale).'/contact');
    }

    /** An accommodation or page, with its address in that language. */
    public static function of(Property|Page $record, ?string $locale = null, array $query = []): string
    {
        $locale ??= app()->getLocale();

        return self::to(self::prefix($locale).'/'.$record->slugFor($locale), $query);
    }

    /** The language codes the route accepts as a prefix (checked further in SetLocale). */
    public static function languagePattern(): string
    {
        return implode('|', array_map(fn (string $code) => preg_quote($code, '#'), Languages::available()));
    }

    /**
     * The same address in another language. An accommodation or page gets its
     * slug in that language; the indexable pages get the prefix. Other
     * addresses (booking, My bookings) only get the slug of that language and
     * no prefix. Anything else stays as it is. The query string is kept.
     */
    public static function inLanguage(string $address, string $locale): string
    {
        $segments = array_values(array_filter(explode('/', (string) parse_url($address, PHP_URL_PATH)), 'strlen'));
        parse_str((string) parse_url($address, PHP_URL_QUERY), $query);

        if (isset($segments[0]) && in_array($segments[0], Languages::available(), true)) {
            array_shift($segments);
        }

        $record = isset($segments[0]) ? self::record($segments[0]) : null;

        return match (true) {
            $segments === [] => self::home($locale, $query),
            $segments === ['contact'] => self::to(self::prefix($locale).'/contact', $query),
            $record !== null && count($segments) === 1 => self::of($record, $locale, $query),
            $record !== null => self::to('/'.implode('/', [$record->slugFor($locale), ...array_slice($segments, 1)]), $query),
            default => $address,
        };
    }

    /**
     * The address of the current page in every active language, plus
     * x-default (the default language), for search engines. Null when this is
     * not a page search engines index.
     *
     * @return array<string, string>|null
     */
    public static function alternates(?Request $request = null): ?array
    {
        $request ??= request();
        $address = match (self::routeName($request)) {
            'home' => fn (string $locale) => self::home($locale),
            'contact' => fn (string $locale) => self::contact($locale),
            'properties.show' => ($record = self::recordOfRequest($request)) === null
                ? null
                : fn (string $locale) => self::of($record, $locale),
            default => null,
        };

        if ($address === null) {
            return null;
        }

        $addresses = collect(Languages::active())->mapWithKeys(fn (string $locale) => [$locale => $address($locale)])->all();

        return [...$addresses, 'x-default' => $address(Languages::default())];
    }

    /** Is this request for one of the indexable pages? */
    public static function isIndexable(Request $request): bool
    {
        return in_array(self::routeName($request), self::INDEXABLE, true);
    }

    /** The route name without the prefix of the language versions. */
    private static function routeName(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        return str_starts_with($name, self::PREFIXED) ? substr($name, strlen(self::PREFIXED)) : $name;
    }

    /** The accommodation or page this request shows. */
    public static function recordOfRequest(Request $request): Property|Page|null
    {
        $value = $request->route('property');

        return $value instanceof Property ? $value : (is_string($value) ? self::record($value) : null);
    }

    private static function record(string $slug): Property|Page|null
    {
        return Property::findBySlug($slug) ?? Page::findBySlug($slug);
    }

    private static function to(string $path, array $query = []): string
    {
        return url($path).($query === [] ? '' : '?'.http_build_query($query));
    }
}
