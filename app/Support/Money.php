<?php

namespace App\Support;

use App\Models\Property;
use App\Models\Setting;
use App\Services\ExchangeRates;
use Illuminate\Support\Facades\Log;

/**
 * Amounts are stored as cents (hundredths) in the database, in the currency of
 * the accommodation or the booking. Pass that currency along; without one, the
 * default currency of the default accommodation settings applies.
 */
class Money
{
    private const DEFAULT_MEMORY = 'money.default_currency';

    /** While true every amount is shown in its own currency (see inChargedCurrency). */
    private static bool $charged = false;

    public static function format(?int $cents, ?string $currency = null): string
    {
        $cents ??= 0;
        $currency = self::code($currency);

        if (($view = self::displayCurrencyFor($currency)) !== null) {
            return self::currencyText(self::convertedAmount($cents, $currency, $view), $view);
        }

        return self::currencyText($cents / 100, $currency);
    }

    /**
     * Compact display for e.g. the calendar: whole amounts without decimals
     * (€120), otherwise with (€120,50). No space, to keep it small.
     */
    public static function compact(?int $cents, ?string $currency = null): string
    {
        $cents ??= 0;
        $currency = self::code($currency);

        if (($view = self::displayCurrencyFor($currency)) !== null) {
            return self::currencyText(self::convertedAmount($cents, $currency, $view), $view, compact: true);
        }

        return self::currencyText($cents / 100, $currency, compact: true);
    }

    /** The currency of the default accommodation settings; remembered per request. */
    public static function defaultCurrency(): string
    {
        return RequestMemory::remember(self::DEFAULT_MEMORY, fn () => Property::master()->priceCurrency());
    }

    public static function forgetsDefaultCurrency(): void
    {
        RequestMemory::forgets(self::DEFAULT_MEMORY);
    }

    /**
     * An amount in cents from one currency to another, at the current rate.
     * Without a rate the amount stays as it is (and a warning goes in the log).
     * That is fine for a figure on a screen, not for a price to charge:
     * PricingEngine::quote refuses to make a price without a rate.
     */
    public static function convert(?int $cents, string $of, string $to): ?int
    {
        if ($cents === null || strtoupper($of) === strtoupper($to)) {
            return $cents;
        }

        $exchangeRate = self::exchangeRate($of, $to);

        if ($exchangeRate === null) {
            Log::warning('No exchange rate to convert an amount', ['of' => $of, 'to' => $to]);

            return $cents;
        }

        return (int) round($cents * $exchangeRate);
    }

    /**
     * Amounts in different currencies together, converted to the default
     * currency (for revenue figures across all accommodations).
     *
     * @param  iterable<string, int>  $centsPerCurrency  currency => cents
     */
    public static function inDefaultCurrency(iterable $centsPerCurrency): int
    {
        $default = self::defaultCurrency();
        $total = 0;

        foreach ($centsPerCurrency as $currency => $cents) {
            $total += (int) self::convert((int) $cents, (string) $currency, $default);
        }

        return $total;
    }

    /** Rate from one currency to another (via the euro), or null. */
    public static function exchangeRate(string $of, string $to): ?float
    {
        $of = strtoupper($of);
        $to = strtoupper($to);

        if ($of === $to) {
            return 1.0;
        }

        $ofEuro = ExchangeRates::exchangeRate($of);
        $toEuro = ExchangeRates::exchangeRate($to);

        return $ofEuro === null || $toEuro === null || $ofEuro <= 0.0 ? null : $toEuro / $ofEuro;
    }

    /**
     * The currency in which the visitor wants to see prices, or null if the
     * site offers no choice.
     *
     * Deliberately restricted to the public site: mails, PDFs, commands and
     * the admin panel ALWAYS show the currency that is charged; this is purely
     * display for the guest. A document or mail made during a visit to a site
     * page says so itself, with inChargedCurrency().
     */
    public static function displayCurrency(): ?string
    {
        if (self::$charged || ! app()->bound('request')) {
            return null;
        }

        $request = request();

        // Only when SHOWING site pages (GET): mails rendered during a POST
        // (booking, contact) and the PDFs/admin thus stay guaranteed in the
        // real currency.
        if ($request === null || ! $request->isMethod('GET') || $request->is('admin*') || $request->is('livewire*') || ! $request->hasSession()) {
            return null;
        }

        if (Setting::get('site', 'currency_picker', '0') !== '1') {
            return null;
        }

        // The visitor's own choice, otherwise the configured default currency.
        $currency = strtoupper((string) ($request->session()->get('currency')
            ?: Setting::get('site', 'currency_default', 'EUR')));

        return $currency !== '' && in_array($currency, self::availableCurrencies(), true) ? $currency : null;
    }

    /**
     * Run $callback with every amount in the currency that is charged, whatever
     * the visitor picked to see prices in. For what is kept as a record: an
     * invoice, receipt or confirmation, also when a guest downloads it from a
     * site page, and a mail that is sent while a page is shown.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function inChargedCurrency(callable $callback): mixed
    {
        $before = self::$charged;
        self::$charged = true;

        try {
            return $callback();
        } finally {
            self::$charged = $before;
        }
    }

    /**
     * The currency to display an amount in $currency in, if that is a different
     * one and there is a rate for it; otherwise null (just show its own currency).
     */
    public static function displayCurrencyFor(string $currency): ?string
    {
        $view = self::displayCurrency();

        return $view !== null && $view !== strtoupper($currency) && self::exchangeRate($currency, $view) !== null
            ? $view
            : null;
    }

    /**
     * The currencies the site offers: everything for which a rate is
     * available — no manual list.
     *
     * @return string[]
     */
    public static function availableCurrencies(): array
    {
        return Currencies::codes();
    }

    private static function code(?string $currency): string
    {
        return strtoupper($currency ?: self::defaultCurrency());
    }

    private static function convertedAmount(int $cents, string $of, string $to): float
    {
        return ($cents / 100) * (float) self::exchangeRate($of, $to);
    }

    private static function currencyText(float $amount, string $currency, bool $compact = false): string
    {
        // Euros as always, also after converting: "€ 1.234,50" and compact "€120".
        if ($currency === 'EUR') {
            $decimals = $compact && floor($amount) == $amount ? 0 : 2;

            return '€'.($compact ? '' : ' ').number_format($amount, $decimals, ',', '.');
        }

        if (extension_loaded('intl')) {
            $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::CURRENCY);

            if ($compact && floor($amount) == $amount) {
                $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 0);
            }

            $text = $formatter->formatCurrency($amount, $currency);

            if ($text !== false) {
                return $compact ? str_replace("\u{00A0}", '', $text) : $text;
            }
        }

        return $currency.' '.number_format($amount, $compact && floor($amount) == $amount ? 0 : 2, ',', '.');
    }
}
