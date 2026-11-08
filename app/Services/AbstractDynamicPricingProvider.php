<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Setting;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Shared base for all HTTP pricing services (PriceLabs, Beyond,
 * Wheelhouse...). The guard logic (configured? listing linked?) and the conversion
 * to the accommodation's currency live here once; each service only has to
 * supply its API key setting and its own HTTP call. That makes a new
 * competitor one small class — no duplicate code.
 */
abstract class AbstractDynamicPricingProvider implements DynamicPricingProvider
{
    /**
     * Key in the 'prices' settings group where the API key is stored,
     * e.g. 'pricelabs_api_key'.
     */
    abstract protected function apiKeySetting(): string;

    /**
     * The actual API call: price per night in cents, and the currency
     * if the service provides one.
     */
    abstract protected function requestPrices(string $apiKey, string $listingId, CarbonImmutable $from, CarbonImmutable $to): DynamicPrices;

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    public function pricesFor(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $this->isConfigured() || blank($property->external_listing_id)) {
            return [];
        }

        return $this->inCurrencyOf($property, $this->requestPrices($this->apiKey(), $property->external_listing_id, $from, $to));
    }

    /**
     * Prices in the accommodation's currency. If the service returns a different
     * currency (e.g. the listing is in dollars at PriceLabs, the accommodation
     * here in pesos), it is converted. Without a rate, nothing: a wrong
     * amount in the calendar is worse than no dynamic price.
     *
     * @return array<string, int>
     */
    private function inCurrencyOf(Property $property, DynamicPrices $answer): array
    {
        $target = $property->priceCurrency();
        $source = strtoupper((string) $answer->currency);

        if ($source === '' || $source === $target || $answer->prices === []) {
            return $answer->prices;
        }

        if (Money::exchangeRate($source, $target) === null) {
            Log::warning('Dynamic prices skipped: no exchange rate to convert with', [
                'property_id' => $property->id, 'from' => $source, 'to' => $target,
            ]);

            return [];
        }

        Log::info('Dynamic prices converted to the accommodation currency', [
            'property_id' => $property->id, 'from' => $source, 'to' => $target,
        ]);

        return array_map(fn (int $cents) => (int) Money::convert($cents, $source, $target), $answer->prices);
    }

    protected function apiKey(): ?string
    {
        return Setting::get('prices', $this->apiKeySetting());
    }
}
