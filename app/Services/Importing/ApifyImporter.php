<?php

namespace App\Services\Importing;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Import via an external scraping service (Apify). The buyer enters their own
 * Apify token and actor ID and thereby accepts the source platform's terms
 * themselves — the scraping happens at Apify, not in this code.
 *
 * NOTE: the exact actor and output format differ per Apify actor;
 * the mapping below must be adapted to the chosen actor. Without a token
 * the system falls back to the safe MetadataImporter.
 */
class ApifyImporter implements ListingImportProvider
{
    public function __construct(private readonly MetadataImporter $fallback) {}

    public function import(string $url): ListingDraft
    {
        $token = Setting::get('import', 'apify_token');
        $actor = Setting::get('import', 'apify_actor');

        if (blank($token) || blank($actor)) {
            return $this->fallback->import($url);
        }

        // Apify: run-sync-get-dataset-items runs the actor and returns the
        // results directly. The input shape depends on the chosen actor.
        $response = Http::timeout(120)
            ->withToken($token)
            ->post("https://api.apify.com/v2/acts/{$actor}/run-sync-get-dataset-items", [
                'startUrls' => [['url' => $url]],
            ]);

        if (! $response->successful()) {
            throw new ListingImportException(__('The import service returned an error (status :status).', ['status' => $response->status()]));
        }

        $row = $response->json('0') ?? $response->json()[0] ?? null;

        if (! is_array($row)) {
            return $this->fallback->import($url);
        }

        return new ListingDraft(
            name: $row['name'] ?? $row['title'] ?? null,
            shortDescription: $row['summary'] ?? null,
            description: $row['description'] ?? null,
            city: $row['city'] ?? ($row['address']['city'] ?? null),
            country: $row['country'] ?? null,
            latitude: isset($row['lat']) ? (float) $row['lat'] : (isset($row['latitude']) ? (float) $row['latitude'] : null),
            longitude: isset($row['lng']) ? (float) $row['lng'] : (isset($row['longitude']) ? (float) $row['longitude'] : null),
            maxGuests: isset($row['personCapacity']) ? (int) $row['personCapacity'] : null,
            bedrooms: isset($row['bedrooms']) ? (int) $row['bedrooms'] : null,
            beds: isset($row['beds']) ? (int) $row['beds'] : null,
            bathrooms: isset($row['bathrooms']) ? (int) $row['bathrooms'] : null,
            basePriceCents: isset($row['price']) ? (int) round(((float) $row['price']) * 100) : null,
            photoUrls: array_values(array_filter((array) ($row['photos'] ?? $row['images'] ?? []), 'is_string')),
            sourceUrl: $url,
        );
    }
}
