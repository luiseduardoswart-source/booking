<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Current exchange rates (base EUR), free and without a key. Primary source is
 * open.er-api.com (~160 currencies, including South American ones such as
 * COP/ARS/CLP); if that fails, the daily mirror on jsDelivr. Rates are cached
 * for 12 hours; a failed attempt briefly, so it retries quickly.
 *
 * Prices are made with these rates (an accommodation in pesos that takes over
 * an amount from a default in euros), so a failed fetch must never leave us
 * without one: the last list that was fetched is kept in the database and
 * used until a new one arrives. Without any rate the display falls back to
 * the own currency, and no price is made (PricingEngine).
 */
class ExchangeRates
{
    private const CACHE = 'exchange_rates.eur.v2';

    /** Setting (group, key) that keeps the last fetched list. */
    private const LAST_KNOWN = ['system', 'exchange_rates'];

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
        $cached = Cache::get(self::CACHE);

        if (is_array($cached)) {
            return $cached;
        }

        $exchangeRates = self::fetch();

        if ($exchangeRates !== []) {
            Cache::put(self::CACHE, $exchangeRates, now()->addHours(12));
            self::keep($exchangeRates);

            return $exchangeRates;
        }

        // Both sources failed: yesterday's rates are far closer to the truth
        // than none. Try again in 10 minutes.
        $exchangeRates = self::lastKnown();
        Cache::put(self::CACHE, $exchangeRates, now()->addMinutes(10));
        Log::warning('Exchange rates could not be fetched', ['using_last_known' => $exchangeRates !== []]);

        return $exchangeRates;
    }

    /**
     * Only real rates: a rate of zero (or less) would turn every converted
     * price into zero.
     *
     * @param  array<array-key, mixed>  $rates
     * @return array<string, float>
     */
    private static function usable(array $rates): array
    {
        return collect($rates)
            ->filter(fn ($exchangeRate) => is_numeric($exchangeRate) && (float) $exchangeRate > 0)
            ->mapWithKeys(fn ($exchangeRate, $code) => [strtoupper((string) $code) => (float) $exchangeRate])
            ->filter(fn (float $exchangeRate, string $code) => preg_match('/^[A-Z]{3}$/', $code) === 1)
            ->all();
    }

    /** @param  array<string, float>  $exchangeRates */
    private static function keep(array $exchangeRates): void
    {
        try {
            Setting::set(...[...self::LAST_KNOWN, json_encode($exchangeRates)]);
        } catch (Throwable $e) {
            report($e); // never break the site on this
        }
    }

    /** @return array<string, float> */
    private static function lastKnown(): array
    {
        try {
            $kept = json_decode((string) Setting::get(...self::LAST_KNOWN), true);
        } catch (Throwable) {
            return []; // no database yet (installation)
        }

        return is_array($kept) ? self::usable($kept) : [];
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

            if (is_array($rates) && self::usable($rates) !== []) {
                return self::usable($rates);
            }
        } catch (Throwable) {
            // on to the fallback source
        }

        // 2. Fallback: daily rates mirror via jsDelivr (lowercase codes).
        try {
            $response = Http::timeout(8)->get('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/eur.json');
            $rates = $response->successful() ? $response->json('eur') : null;

            if (is_array($rates) && self::usable($rates) !== []) {
                return self::usable($rates);
            }
        } catch (Throwable) {
            // below: empty list
        }

        return [];
    }
}
