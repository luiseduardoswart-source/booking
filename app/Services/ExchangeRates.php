<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Current exchange rates (base EUR), free and without a key. Primary source is
 * open.er-api.com (~160 currencies, including South American ones such as
 * COP/ARS/CLP); if that fails, the daily mirror on jsDelivr. Rates are cached
 * for 12 hours; a failed attempt briefly, so it retries quickly. Without a
 * rate everything silently falls back to euros — the site never breaks on this.
 */
class ExchangeRates
{
    /**
     * Rate EUR → currency, or null if the currency is unknown or the rates
     * could not be fetched.
     */
    public static function exchangeRate(string $currency): ?float
    {
        $currency = strtoupper(trim($currency));

        if ($currency === 'EUR') {
            return 1.0;
        }

        $exchangeRates = self::exchangeRates();

        return isset($exchangeRates[$currency]) ? (float) $exchangeRates[$currency] : null;
    }

    /**
     * @return array<string, float> currency code (uppercase) => rate relative to EUR
     */
    public static function exchangeRates(): array
    {
        $cached = Cache::get('exchange_rates.eur.v2');

        if (is_array($cached)) {
            return $cached;
        }

        $exchangeRates = self::fetch();

        // Success: keep 12 hours. Failure: 10 minutes, then try again.
        Cache::put('exchange_rates.eur.v2', $exchangeRates, $exchangeRates === [] ? now()->addMinutes(10) : now()->addHours(12));

        return $exchangeRates;
    }

    /**
     * @return array<string, float>
     */
    private static function fetch(): array
    {
        // 1. open.er-api.com — ~160 currencies.
        try {
            $response = Http::timeout(8)->get('https://open.er-api.com/v6/latest/EUR');
            $rates = $response->successful() ? $response->json('rates') : null;

            if (is_array($rates) && $rates !== []) {
                return collect($rates)
                    ->mapWithKeys(fn ($exchangeRate, $code) => [strtoupper((string) $code) => (float) $exchangeRate])
                    ->all();
            }
        } catch (\Throwable) {
            // on to the fallback source
        }

        // 2. Fallback: daily rates mirror via jsDelivr (lowercase codes).
        try {
            $response = Http::timeout(8)->get('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/eur.json');
            $rates = $response->successful() ? $response->json('eur') : null;

            if (is_array($rates) && $rates !== []) {
                return collect($rates)
                    ->mapWithKeys(fn ($exchangeRate, $code) => [strtoupper((string) $code) => (float) $exchangeRate])
                    ->filter(fn (float $exchangeRate, string $code) => preg_match('/^[A-Z]{3}$/', $code) === 1)
                    ->all();
            }
        } catch (\Throwable) {
            // below: empty list
        }

        return [];
    }
}
