<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Beyond (formerly Beyond Pricing) — dynamic prices, following the
 * documentation at developers.beyondpricing.com:
 *
 * - Authorization: Bearer with a personal token (bpat_…, requires Beyond
 *   Pro); the token stays on the server.
 * - GET /api/v1/listings/{id}/          → data.attributes.currency
 * - GET /api/v1/listings/{id}/calendar/ → JSON:API, per day
 *   data[].attributes.date and price-posted (otherwise price), in whole
 *   currency units (not cents), in the listing's currency.
 */
class BeyondProvider extends AbstractDynamicPricingProvider
{
    private const BASE_URL = 'https://developers.beyondpricing.com/api/v1';

    /** Maximum per page (1 page = 731 days, a little over a year ahead). */
    private const PER_PAGE = 731;

    /** Limit on following further pages, so an error never loops endlessly. */
    private const MAX_PAGES = 5;

    protected function apiKeySetting(): string
    {
        return 'beyond_api_key';
    }

    protected function requestPrices(string $apiKey, string $listingId, CarbonImmutable $from, CarbonImmutable $to): DynamicPrices
    {
        $listing = $this->request($apiKey)->get(self::BASE_URL.'/listings/'.rawurlencode($listingId).'/');

        if (! $listing->successful()) {
            Log::warning('Beyond: fetching listing failed', ['listing_id' => $listingId, 'status' => $listing->status()]);

            return DynamicPrices::no();
        }

        $prices = [];
        $url = self::BASE_URL.'/listings/'.rawurlencode($listingId).'/calendar/';
        $query = [
            'filter[start-date]' => $from->toDateString(),
            'filter[end-date]' => $to->toDateString(),
            'page[size]' => self::PER_PAGE,
        ];

        for ($page = 1; $url !== null && $page <= self::MAX_PAGES; $page++) {
            $response = $this->request($apiKey)->get($url, $query);

            if (! $response->successful()) {
                Log::warning('Beyond: fetching calendar failed', ['listing_id' => $listingId, 'status' => $response->status()]);

                return DynamicPrices::no();
            }

            foreach ($response->json('data') ?? [] as $row) {
                $day = $row['attributes'] ?? [];
                // The price sent to the channel; otherwise the recommendation.
                $price = $day['price-posted'] ?? $day['price'] ?? null;

                if (! isset($day['date']) || ! is_numeric($price) || $price < 0) {
                    continue;
                }

                $prices[CarbonImmutable::parse($day['date'])->toDateString()] = (int) round(((float) $price) * 100);
            }

            // Next page: the full URL is in links.next.
            $url = $response->json('links.next');
            $query = [];
        }

        return new DynamicPrices($prices, $listing->json('data.attributes.currency'));
    }

    private function request(string $apiKey)
    {
        return Http::withToken($apiKey)->acceptJson();
    }
}
