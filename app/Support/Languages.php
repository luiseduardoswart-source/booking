<?php

namespace App\Support;

use App\Models\Language;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Which languages the website shows and which of them is the default. The list
 * lives in the `languages` table and can be managed under Settings → Website → Languages.
 *
 * Available = all languages in the table (including disabled ones), because
 * filled-in translations still exist for them that we must not delete. Active = what the
 * visitor can choose on the site.
 *
 * The admin languages are separate from this, see AdminLanguages.
 */
class Languages
{
    /** The language of the main columns: translations are made from it. See HasTranslations. */
    public const SOURCE = 'en';

    /** Suggestions for the name when creating; the owner may change it. */
    public const NAMES = [
        'nl' => 'Nederlands',
        'en' => 'English',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'es' => 'Español',
        'zh' => '中文',
        'it' => 'Italiano',
        'pt' => 'Português',
        'pl' => 'Polski',
        'da' => 'Dansk',
        'sv' => 'Svenska',
        'no' => 'Norsk',
    ];

    /**
     * @return array<int, string>
     */
    public static function available(): array
    {
        return static::offTable(fn ($languages) => $languages->pluck('code')->all());
    }

    /**
     * @return array<int, string>
     */
    public static function active(): array
    {
        $codes = static::offTable(
            fn ($languages) => $languages->where('active', true)->pluck('code')->values()->all()
        );

        // The default language is always included: otherwise the site would open in
        // a language the visitor cannot even choose.
        $default = static::default();

        if (! in_array($default, $codes, true)) {
            array_unshift($codes, $default);
        }

        return $codes;
    }

    public static function default(): string
    {
        $code = static::offTable(
            fn ($languages) => $languages->firstWhere('is_default', true)?->code
                ?? $languages->firstWhere('active', true)?->code,
            null
        );

        return $code ?? config('app.locale');
    }

    public static function name(string $locale): string
    {
        $offTable = static::offTable(
            fn ($languages) => $languages->firstWhere('code', $locale)?->name,
            null
        );

        return $offTable ?: (static::NAMES[$locale] ?? strtoupper($locale));
    }

    /**
     * @return Collection<int, Language>
     */
    protected static function table(): Collection
    {
        return Language::allCached();
    }

    /**
     * The table does not exist yet during the install wizard, and on a fresh
     * installation it can be empty. In both cases fall back to the languages
     * shipped with the software.
     *
     * @template T
     *
     * @param  callable(Collection<int, Language>): T  $read
     * @return T
     */
    private static function offTable(callable $read, mixed $fallback = null): mixed
    {
        try {
            $languages = static::table();

            if ($languages->isNotEmpty()) {
                return $read($languages);
            }
        } catch (Throwable) {
            // table does not exist yet
        }

        return func_num_args() > 1 ? $fallback : config('app.supported_locales');
    }
}
