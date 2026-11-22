<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\ExchangeRates;

/**
 * Helpers around currency codes: the full select list (everything for which a
 * rate is available), plus names and symbols in the user's language
 * via ICU. No manually maintained lists.
 */
class Currencies
{
    /**
     * Fixed core in case the exchange rate source is not (yet) reachable.
     */
    private const BASE = [
        'EUR', 'USD', 'GBP', 'CHF', 'AUD', 'CAD', 'NZD', 'SEK', 'NOK', 'DKK',
        'PLN', 'CZK', 'JPY', 'CNY', 'BRL', 'MXN', 'ARS', 'CLP', 'COP', 'PEN',
        'ZAR', 'TRY', 'AED', 'INR', 'IDR', 'THB', 'SGD', 'HKD', 'KRW',
    ];

    /**
     * The currencies at the top of every list, in this order, until the owner
     * chooses their own (Settings > Website > Currency).
     */
    public const POPULAR = ['EUR', 'USD', 'JPY', 'GBP', 'COP'];

    /**
     * The currencies at the top: the owner's choice, otherwise POPULAR.
     *
     * @return string[]
     */
    public static function popular(): array
    {
        $saved = Setting::get('site', 'currency_popular');
        $codes = $saved === null ? self::POPULAR : explode(',', $saved);

        return collect($codes)
            ->map(fn (string $code) => strtoupper(trim($code)))
            ->filter(fn (string $code) => preg_match('/^[A-Z]{3}$/', $code) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * All available currency codes: the popular ones first, then alphabetical.
     *
     * @return string[]
     */
    public static function codes(): array
    {
        $codes = array_keys(ExchangeRates::exchangeRates());

        if ($codes === []) {
            $codes = self::BASE;
        }

        $codes = collect($codes)
            ->push('EUR')
            ->map(fn (string $code) => strtoupper($code))
            ->filter(fn (string $code) => preg_match('/^[A-Z]{3}$/', $code) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Only popular currencies that have a rate (EUR always has one).
        $popular = array_values(array_intersect(self::popular(), $codes));

        return [...$popular, ...array_values(array_diff($codes, $popular))];
    }

    /**
     * Select list for forms: code => "CODE — Name (symbol)".
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::codes())
            ->mapWithKeys(function (string $code) {
                $name = self::name($code);
                $symbol = self::symbol($code);
                $extra = $symbol !== null && $symbol !== $code ? " ({$symbol})" : '';

                return [$code => $name !== null ? "{$code} — {$name}{$extra}" : $code];
            })
            ->all();
    }

    /**
     * The name of a currency in the current language (via ICU), or null.
     */
    public static function name(string $code): ?string
    {
        $details = self::icuDetails($code);

        return $details['name'] ?? null;
    }

    /** The character to put before an amount: the symbol, otherwise the code (e.g. "COP"). */
    public static function character(string $code): string
    {
        return self::symbol($code) ?? strtoupper($code);
    }

    /**
     * The symbol of a currency ($, £, ₱ …), or null.
     */
    public static function symbol(string $code): ?string
    {
        $details = self::icuDetails($code);

        return $details['symbol'] ?? null;
    }

    /**
     * The short symbol for next to the code ("USD $", "JPY ¥"), or null if the
     * currency has none (CHF, AED). Unlike symbol() it does not tell currencies
     * apart ("US$", "MX$"), so it never stands without the code. Languages
     * without one of their own use the international one.
     */
    public static function shortSymbol(string $code): ?string
    {
        if (! extension_loaded('intl')) {
            return null;
        }

        $code = strtoupper($code);

        foreach ([app()->getLocale(), 'root'] as $locale) {
            try {
                $symbol = \ResourceBundle::create($locale, 'ICUDATA-curr')?->get('Currencies%narrow')?->get($code);
            } catch (\Throwable) {
                $symbol = null;
            }

            if (is_string($symbol) && $symbol !== '' && $symbol !== $code) {
                return $symbol;
            }
        }

        return null;
    }

    /**
     * @return array{symbol: ?string, name: ?string}
     */
    private static function icuDetails(string $code): array
    {
        if (! extension_loaded('intl')) {
            return ['symbol' => null, 'name' => null];
        }

        try {
            $bundle = \ResourceBundle::create(app()->getLocale(), 'ICUDATA-curr');
            $currency = $bundle?->get('Currencies')?->get(strtoupper($code));

            if ($currency === null) {
                return ['symbol' => null, 'name' => null];
            }

            return [
                'symbol' => is_string($currency->get(0)) ? $currency->get(0) : null,
                'name' => is_string($currency->get(1)) ? $currency->get(1) : null,
            ];
        } catch (\Throwable) {
            return ['symbol' => null, 'name' => null];
        }
    }
}
