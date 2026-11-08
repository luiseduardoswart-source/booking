<?php

namespace App\Services\Importing;

use App\Models\Property;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a fetched ListingDraft into a (draft) accommodation: fills in the
 * fields, makes a unique slug and downloads the photos. The user then finishes
 * the property in the wizard (the edit screen).
 */
class ListingImportService
{
    public function __construct(private readonly ListingImportProvider $provider) {}

    public function importFromUrl(string $url): Property
    {
        $draft = $this->provider->import($url);

        return $this->createFromDraft($draft);
    }

    public function createFromDraft(ListingDraft $draft): Property
    {
        $name = $draft->name ?: __('Imported property');

        $property = Property::create(array_merge([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'type' => 'holiday_home',
            'active' => false, // draft: check and finish first
            'max_guests' => $draft->maxGuests ?: 2,
        ], $draft->toAttributes()));

        $this->downloadPhotos($property, $draft->photoUrls);

        return $property;
    }

    /**
     * Downloads external photos to the media library. Reused by the
     * import action and the quick wizard.
     *
     * @param  array<int, string>  $urls
     */
    public function downloadPhotos(Property $property, array $urls): void
    {
        // The same photo often appears at several URLs (thumbnail + large size)
        // with the same file name. Deduplicate on file name so the same
        // photo does not come in twice (in different sizes).
        $seen = [];

        foreach (array_slice($urls, 0, 30) as $url) {
            $baseName = strtolower(basename((string) parse_url($url, PHP_URL_PATH)));

            if ($baseName !== '' && isset($seen[$baseName])) {
                continue;
            }
            $seen[$baseName] = true;

            try {
                $property->addMediaFromUrl($url)->toMediaCollection('photos');
            } catch (Throwable) {
                // One failed photo must not block the whole import.
            }
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'accommodation';
        $slug = $base;
        $n = 1;

        while (Property::withoutGlobalScope('withoutMaster')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
