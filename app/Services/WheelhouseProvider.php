<?php

namespace App\Services;

use App\Support\RequestMemory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wheelhouse — dynamic prices, following the documentation of the Revenue
 * Management API (docs.usewheelhouse.com, api.usewheelhouse.com):
 *
 * - header X-Integration-Api-Key (the API key from the Wheelhouse account);
 * - GET /listings → per listing `id` and `channel` (needed for the prices);
 * - GET /listings/{id}/price_recommendations?channel=… → data[] with
 *   `stay_date`, `price` and `currency`, starting today.
 *
 * We treat prices as whole currency units; the documentation does not say so
 * literally for this response, but elsewhere it works in whole dollars.
 */
class WheelhouseProvider extends AbstractDynamicPricingProvider
{
    private const BASE_URL = 'https://api.usewheelhouse.com/ss_api/v1';

    protected function apiKeySetting(): string
    {
        return 'wheelhouse_api_key';
    }

    protected function requestPrices(string $apiKey, string $listingId, CarbonImmutable $from, CarbonImmutable $to): DynamicPrices
    {
        $channel = $this->channels($apiKey)[$listingId] ?? null;

        if ($channel === null) {
            Log::warning('Wheelhouse: listing not found in the account', ['listing_id' => $listingId]);

            return DynamicPrices::no();
        }

        $response = $this->request($apiKey)->get(
            self::BASE_URL.'/listings/'.rawurlencode($listingId).'/price_recommendations',
            ['channel' => $channel],
        );

        if (! $response->successful()) {
            // 423 = the recommendations are still being calculated; try again the next night.
            Log::warning('Wheelhouse: fetching price recommendations failed', ['listing_id' => $listingId, 'status' => $response->status()]);

            return DynamicPrices::no();
        }

        $rows = collect($response->json('data') ?? [])->filter(fn ($row) => is_array($row));
        $currency = $rows->first()['currency'] ?? null;
        $prices = [];

        foreach ($rows as $row) {
            if (! isset($row['stay_date']) || ! is_numeric($row['price'] ?? null) || $row['price'] < 0) {
                continue;
            }

            // One currency per response: we skip a row in a different currency.
            if (($row['currency'] ?? $currency) !== $currency) {
                continue;
            }

            $day = CarbonImmutable::parse($row['stay_date']);

            if ($day->lessThan($from) || $day->greaterThan($to)) {
                continue;
            }

            $prices[$day->toDateString()] = (int) round(((float) $row['price']) * 100);
        }

        return new DynamicPrices($prices, $currency);
    }

    /**
     * Listing ID => channel, for all listings of the account. Fetched once per
     * synchronisation.
     *
     * @return array<string, string>
     */
    private function channels(string $apiKey): array
    {
        return RequestMemory::remember('wheelhouse.listings.'.hash('sha256', $apiKey), function () use ($apiKey): array {
            $response = $this->request($apiKey)->get(self::BASE_URL.'/listings');

            if (! $response->successful()) {
                Log::warning('Wheelhouse: fetching the list of listings failed', ['status' => $response->status()]);

                return [];
            }

            $listings = $response->json('data') ?? $response->json() ?? [];

            return collect(is_array($listings) ? $listings : [])
                ->filter(fn ($listing) => is_array($listing) && filled($listing['id'] ?? null) && filled($listing['channel'] ?? null))
                ->mapWithKeys(fn (array $listing) => [(string) $listing['id'] => (string) $listing['channel']])
                ->all();
        });
    }

    private function request(string $apiKey)
    {
        return Http::withHeaders(['X-Integration-Api-Key' => $apiKey])->acceptJson();
    }
}
