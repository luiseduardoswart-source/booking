<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * The fixed words of the website (Search, Share, Book). They live in the
 * language files; what the owner changes in the admin is stored per language in
 * the settings and takes precedence over the file. That way it survives every deploy.
 *
 * This only applies to the website: the same words in the admin stay those
 * from the file, see usage().
 */
class WebsiteTexts
{
    private const GROUP = 'website_texts';

    private const OFF = 'website_texts.off';

    /** Where the texts a guest gets to see are located, per kind. */
    public const SOURCES = [
        'Website' => ['resources/views/site', 'resources/views/templates', 'resources/views/components'],
        'Emails' => ['resources/views/mail'],
        'Documents' => ['resources/views/pdf'],
        'Messages' => ['app/Http/Controllers', 'app/Engines', 'app/Enums', 'app/Mail'],
    ];

    /**
     * All fixed texts, per hash: a text does not fit neatly in an address or
     * form field, and this way nothing can be stored that does not exist.
     *
     * @return array<string, array{key: string, kind: string}>
     */
    public static function all(): array
    {
        return Cache::remember('website_texts.keys', now()->addHour(), fn () => static::searchInCode());
    }

    /** @return array<string, array{key: string, kind: string}> */
    private static function searchInCode(): array
    {
        $texts = [];

        foreach (self::SOURCES as $kind => $folders) {
            $files = Finder::create()->files()->name('*.php')->notPath('Admin')
                ->in(array_map(base_path(...), $folders));

            foreach ($files as $file) {
                preg_match_all('/(?:__|trans_choice|@lang)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $file->getContents(), $hits);

                foreach ($hits[2] as $key) {
                    $key = stripslashes($key);

                    // Keys like validation.required or filament::label are not text, and "..." is not a word.
                    if (preg_match('/\p{L}/u', $key) && ! preg_match('/^[\w-]+(::|\.)[\w.-]+$/', $key)) {
                        $texts[sha1($key)] ??= ['key' => $key, 'kind' => $kind];
                    }
                }
            }
        }

        uasort($texts, fn (array $a, array $b) => strnatcasecmp($a['key'], $b['key']));

        return $texts;
    }

    /**
     * What the owner has changed in this language.
     *
     * @return array<string, string>
     */
    public static function custom(string $locale): array
    {
        if (app()->bound(self::OFF)) {
            return [];
        }

        try {
            $custom = json_decode((string) Setting::get(self::GROUP, $locale), true);
        } catch (Throwable) {
            return []; // the settings do not exist yet during installation
        }

        return is_array($custom) ? $custom : [];
    }

    /**
     * Stores a text. Empty or equal to the file removes the customisation.
     *
     * @return string|null what is wrong, or null if it was stored
     */
    public static function save(string $key, string $locale, ?string $text): ?string
    {
        $text = trim((string) $text);

        // Only texts that contain markup themselves may keep it: some are placed unescaped in a page.
        if (! str_contains($key, '<')) {
            $text = strip_tags($text);
        }

        if ($text !== '' && ($error = static::error($key, $text)) !== null) {
            return $error;
        }

        $custom = static::custom($locale);
        unset($custom[$key]);

        if ($text !== '' && $text !== static::default($key, $locale)) {
            $custom[$key] = $text;
        }

        Setting::set(self::GROUP, $locale, $custom === [] ? null : json_encode($custom, JSON_UNESCAPED_UNICODE));
        app('translator')->setLoaded([]);

        return null;
    }

    /** The text from the language file, without customisations. */
    public static function default(string $key, string $locale): string
    {
        return app('translation.loader')->offFile($locale)[$key] ?? $key;
    }

    /** Placeholders like :name and the plural forms must match, otherwise the sentence breaks on the site. */
    private static function error(string $key, string $text): ?string
    {
        preg_match_all('/:[a-zA-Z_]+/', $key, $hits);
        $missing = array_values(array_filter(array_unique($hits[0]), fn (string $spot) => ! str_contains($text, $spot)));

        if ($missing !== []) {
            return __('This text must keep :words.', ['words' => implode(', ', $missing)]);
        }

        return substr_count($text, '|') === substr_count($key, '|')
            ? null
            : __('Keep as many parts with a | as in the original text.');
    }

    /** The admin uses the files, the website the customised texts. */
    public static function usage(bool $on): void
    {
        if (app()->bound(self::OFF) !== $on) {
            return;
        }

        $on ? app()->forgetInstance(self::OFF) : app()->instance(self::OFF, true);
        app('translator')->setLoaded([]);
    }
}
