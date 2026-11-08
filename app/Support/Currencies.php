<?php

namespace App\Support;

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
     * All available currency codes (EUR first, then alphabetical).
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

        return ['EUR', ...array_values(array_diff($codes, ['EUR']))];
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
