<?php

namespace App\Services;

use App\Support\RequestMemory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PriceLabs integration for dynamic prices, following the Customer API
 * (developers.pricelabs.co):
 *
 * - GET  /v1/listings        → per listing `id`, `pms` and `currency`;
 * - POST /v1/listing_prices  → per listing `currency` and `data[]` with `date`
 *   and `price` (the price PriceLabs recommends and pushes to the channel).
 *
 * Besides the listing ID, the prices need the `pms` the listing sits under in
 * PriceLabs; we look that up in the list of listings, so the admin only has
 * to fill in the listing ID.
 */
class PriceLabsProvider extends AbstractDynamicPricingProvider
{
    private const BASE_URL = 'https://api.pricelabs.co/v1';

    protected function apiKeySetting(): string
    {
        return 'pricelabs_api_key';
    }

    protected function requestPrices(string $apiKey, string $listingId, CarbonImmutable $from, CarbonImmutable $to): DynamicPrices
    {
        $listing = $this->listings($apiKey)[$listingId] ?? null;

        if ($listing === null) {
            Log::warning('PriceLabs: listing not found in the account', ['listing_id' => $listingId]);

            return DynamicPrices::no();
        }

        $response = Http::withHeaders(['X-API-Key' => $apiKey])
            ->acceptJson()
            ->post(self::BASE_URL.'/listing_prices', [
                'listings' => [[
                    'id' => $listingId,
                    'pms' => $listing['pms'],
                    'dateFrom' => $from->toDateString(),
                    'dateTo' => $to->toDateString(),
                ]],
            ]);

        if (! $response->successful()) {
            Log::warning('PriceLabs: fetching prices failed', ['listing_id' => $listingId, 'status' => $response->status()]);

            return DynamicPrices::no();
        }

        $result = collect($response->json() ?? [])->first(fn ($row) => is_array($row) && (string) ($row['id'] ?? '') === $listingId);

        if ($result === null || isset($result['error'])) {
            Log::warning('PriceLabs: no prices for this listing', [
                'listing_id' => $listingId,
                'error_status' => $result['error_status'] ?? null,
            ]);

            return DynamicPrices::no();
        }

        $prices = [];

        foreach ($result['data'] ?? [] as $row) {
            if (! isset($row['date']) || ! is_numeric($row['price'] ?? null) || (float) $row['price'] < 0) {
                continue;
            }

            $prices[CarbonImmutable::parse($row['date'])->toDateString()] = (int) round(((float) $row['price']) * 100);
        }

        return new DynamicPrices($prices, $result['currency'] ?? $listing['currency']);
    }

    /**
     * All listings of the account: id => ['pms' => ..., 'currency' => ...].
     * Fetched once per synchronization, not per accommodation.
     *
     * @return array<string, array{pms: string, currency: ?string}>
     */
    private function listings(string $apiKey): array
    {
        return RequestMemory::remember('pricelabs.listings.'.hash('sha256', $apiKey), function () use ($apiKey): array {
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->acceptJson()
                ->get(self::BASE_URL.'/listings');

            if (! $response->successful()) {
                Log::warning('PriceLabs: fetching the list of listings failed', ['status' => $response->status()]);

                return [];
            }

            return collect($response->json('listings') ?? [])
                ->filter(fn ($listing) => is_array($listing) && filled($listing['id'] ?? null) && filled($listing['pms'] ?? null))
                ->mapWithKeys(fn (array $listing) => [(string) $listing['id'] => [
                    'pms' => (string) $listing['pms'],
                    'currency' => $listing['currency'] ?? null,
                ]])
                ->all();
        });
    }
}
