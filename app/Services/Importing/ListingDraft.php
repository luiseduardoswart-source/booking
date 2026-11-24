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
    ) {
        // Texts from another site are plain text, whichever importer found
        // them: the description is shown as HTML on the accommodation page,
        // so a tag or script in it would run there.
        foreach (['name', 'shortDescription', 'description', 'city', 'country'] as $field) {
            $this->{$field} = self::plainText($this->{$field});
        }
    }

    /** Text without tags; decoded first, so an encoded tag (&lt;img ...&gt;) does not survive as a real one. */
    public static function plainText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));

        return $text === '' ? null : $text;
    }

    /**
     * The filled-in Property fields (without photos; those are downloaded separately).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        // Again here: an importer may fill a field after the draft was made.
        return array_filter([
            'name' => self::plainText($this->name),
            'short_description' => self::plainText($this->shortDescription),
            'description' => self::plainText($this->description),
            'city' => self::plainText($this->city),
            'country' => self::plainText($this->country),
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
