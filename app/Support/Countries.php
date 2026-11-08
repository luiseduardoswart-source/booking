<?php

namespace App\Support;

class Countries
{
    /**
     * ISO 3166-1 alpha-2 country codes. The names come localised from ext-intl,
     * so this list only needs to contain the codes.
     *
     * @var array<int, string>
     */
    private const CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AR', 'AT', 'AU', 'AW', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BN', 'BO', 'BR', 'BS', 'BT', 'BW', 'BY', 'BZ',
        'CA', 'CD', 'CF', 'CG', 'CH', 'CI', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CY', 'CZ',
        'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ',
        'EC', 'EE', 'EG', 'ER', 'ES', 'ET',
        'FI', 'FJ', 'FM', 'FR',
        'GA', 'GB', 'GD', 'GE', 'GH', 'GM', 'GN', 'GQ', 'GR', 'GT', 'GW', 'GY',
        'HN', 'HR', 'HT', 'HU',
        'ID', 'IE', 'IL', 'IN', 'IQ', 'IR', 'IS', 'IT',
        'JM', 'JO', 'JP',
        'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KZ',
        'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY',
        'MA', 'MC', 'MD', 'ME', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MR', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ',
        'NA', 'NE', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NZ',
        'OM',
        'PA', 'PE', 'PG', 'PH', 'PK', 'PL', 'PT', 'PW', 'PY',
        'QA',
        'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SI', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SY', 'SZ',
        'TD', 'TG', 'TH', 'TJ', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TZ',
        'UA', 'UG', 'US', 'UY', 'UZ',
        'VA', 'VC', 'VE', 'VN', 'VU',
        'WS',
        'YE',
        'ZA', 'ZM', 'ZW',
    ];

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * Countries as [code => localised name], sorted by name in the
     * given (or current) language.
     *
     * @return array<string, string>
     */
    public static function options(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $names = [];

        foreach (self::CODES as $code) {
            $name = extension_loaded('intl')
                ? \Locale::getDisplayRegion('-'.$code, $locale)
                : $code;

            $names[$code] = $name !== '' ? $name : $code;
        }

        // Language-aware sorting by name.
        if (extension_loaded('intl') && class_exists(\Collator::class)) {
            (new \Collator($locale))->asort($names);
        } else {
            asort($names);
        }

        return self::$cache[$locale] = $names;
    }

    /**
     * Localised country name for a code (e.g. 'NL' → 'Netherlands').
     * An unknown/empty code is returned unchanged.
     */
    public static function name(?string $code, ?string $locale = null): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        return self::options($locale)[$code] ?? $code;
    }
}
