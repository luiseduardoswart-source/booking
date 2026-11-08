<?php

namespace App\Services\Importing;

/**
 * Normalised result of a listing import, regardless of the source
 * (public metadata, Apify, ...). Everything is optional: what was not found
 * stays null and the user fills it in themselves in the wizard.
 */
class ListingDraft
{
    /**
     * @param  array<int, string>  $photoUrls  external photo URLs to download
     */
    public function __construct(
        public ?string $name = null,
        public ?string $shortDescription = null,
        public ?string $description = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $maxGuests = null,
        public ?int $bedrooms = null,
        public ?int $beds = null,
        public ?int $sofaBeds = null,
        public ?int $bathrooms = null,
        public ?int $basePriceCents = null,
        public array $photoUrls = [],
        public ?string $sourceUrl = null,
    ) {}

    /**
     * The filled-in Property fields (without photos; those are downloaded separately).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return array_filter([
            'name' => $this->name,
            'short_description' => $this->shortDescription,
            'description' => $this->description,
            'city' => $this->city,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'max_guests' => $this->maxGuests,
            'bedrooms' => $this->bedrooms,
            'beds' => $this->beds,
            'sofa_beds' => $this->sofaBeds,
            'bathrooms' => $this->bathrooms,
            'base_price_cents' => $this->basePriceCents,
        ], fn ($value) => $value !== null);
    }
}
