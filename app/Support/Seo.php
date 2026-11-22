<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Property;
use App\Models\Setting;
use Carbon\Carbon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What search engines and social media read in the head of a page: the
 * share image, the language for Open Graph and the structured data
 * (schema.org) Google uses to understand an accommodation and the business.
 */
final class Seo
{
    /**
     * Our amenities that Google's vacation rental data knows, by the English
     * name of the amenity (Amenity::DEFAULT) => Google's name.
     */
    private const AMENITIES = [
        'Air conditioning' => 'ac',
        'Beach access' => 'beachAccess',
        'Crib' => 'crib',
        'Travel cot' => 'crib',
        'Lift' => 'elevator',
        'Indoor fireplace' => 'fireplace',
        'Gym' => 'gymFitnessEquipment',
        'Exercise equipment' => 'gymFitnessEquipment',
        'Heating' => 'heating',
        'Hot tub' => 'hotTub',
        'Iron' => 'ironingBoard',
        'Kitchen' => 'kitchen',
        'Microwave' => 'microwave',
        'BBQ grill' => 'outdoorGrill',
        'Oven' => 'ovenStove',
        'Stove' => 'ovenStove',
        'Terrace or balcony' => 'patio',
        'Swimming pool' => 'pool',
        'TV' => 'tv',
        'Washing machine' => 'washerDryer',
        'Dryer' => 'washerDryer',
        'Wifi' => 'wifi',
    ];

    /** Open Graph wants language_TERRITORY; the usual territory for our language codes. */
    private const OG_LOCALES = [
        'nl' => 'nl_NL', 'en' => 'en_US', 'de' => 'de_DE', 'fr' => 'fr_FR', 'es' => 'es_ES', 'zh' => 'zh_CN',
        'it' => 'it_IT', 'pt' => 'pt_PT', 'pl' => 'pl_PL', 'da' => 'da_DK', 'sv' => 'sv_SE', 'no' => 'nb_NO',
    ];

    /** The fallback when the owner chose no accent colour (the rose of the buttons). */
    public const THEME_COLOR = '#e11d48';

    public static function ogLocale(string $locale): string
    {
        return self::OG_LOCALES[$locale] ?? str_replace('-', '_', $locale);
    }

    /**
     * The photo shown with a shared link: the accommodation's main photo, the
     * page's first hero photo, the share photo from the settings, the first
     * accommodation's photo, or the logo. Null when there is none at all.
     */
    public static function image(?Property $property = null, ?Page $page = null): ?string
    {
        $candidates = [
            fn () => $property?->coverPhotoUrl('large'),
            fn () => media_url($page?->hero_images[0]['image'] ?? null),
            fn () => ($path = Setting::get('seo', 'share_image')) ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null,
            fn () => Property::query()->where('active', true)->orderBy('id')->first()?->coverPhotoUrl('large'),
            fn () => ($logo = Setting::get('branding', 'logo')) ? \Illuminate\Support\Facades\Storage::disk('public')->url($logo) : null,
        ];

        foreach ($candidates as $candidate) {
            if (filled($url = $candidate())) {
                return $url;
            }
        }

        return null;
    }

    /** The title of the home page in the visitor's language, or null (then the company name). */
    public static function homeTitle(): ?string
    {
        return Setting::get('seo', 'home_title_'.app()->getLocale()) ?: null;
    }

    /** The description of the home page: its own text, otherwise the tagline. */
    public static function homeDescription(): ?string
    {
        return Setting::get('seo', 'home_description_'.app()->getLocale())
            ?: Setting::localized('company', 'slogan');
    }

    /**
     * JSON-LD blocks as script tags. JSON_HEX_TAG writes < and > as < and
     * >: text typed in the admin can never close the script tag.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function scripts(array $blocks): string
    {
        return collect($blocks)
            ->map(fn (array $block) => '<script type="application/ld+json">'
                .json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR)
                .'</script>')
            ->implode("\n");
    }

    /**
     * An accommodation as Google's vacation rental. The street, postal code
     * and coordinates only when the owner shows the exact location.
     *
     * @return array<string, mixed>
     */
    public static function vacationRental(Property $property): array
    {
        $exact = $property->showsExactLocation();
        $time = fn (string $field): ?string => ($set = $property->effective($field)) ? Carbon::parse($set)->format('H:i:s') : null;

        $amenities = $property->amenities
            ->map(fn ($amenity) => self::AMENITIES[$amenity->name] ?? null)
            ->filter()
            ->unique()
            ->map(fn (string $name) => ['@type' => 'LocationFeatureSpecification', 'name' => $name, 'value' => true])
            ->values()
            ->push(['@type' => 'LocationFeatureSpecification', 'name' => 'petsAllowed', 'value' => $property->petsAllowed()])
            ->all();

        $rental = [
            '@context' => 'https://schema.org',
            '@type' => 'VacationRental',
            'additionalType' => match ($property->type) {
                'apartment' => 'Apartment',
                'holiday_home' => 'House',
                default => 'VacationRental',
            },
            'name' => $property->localized('name'),
            'identifier' => (string) $property->id,
            'url' => LocalizedUrl::of($property),
            'description' => $property->localized('short_description') ?: null,
            'image' => $property->gallery()
                ->reject(fn (Media $media) => Property::isVideo($media))
                ->map(fn (Media $media) => $media->hasGeneratedConversion('large') ? $media->getUrl('large') : $media->getUrl())
                ->values()
                ->all(),
            'containsPlace' => array_filter([
                '@type' => 'Accommodation',
                'additionalType' => $property->type === 'room' ? 'PrivateRoom' : 'EntirePlace',
                'occupancy' => ['@type' => 'QuantitativeValue', 'value' => (int) $property->max_guests],
                'numberOfBedrooms' => $property->bedrooms ? (int) $property->bedrooms : null,
                'numberOfBathroomsTotal' => $property->bathrooms ? (int) $property->bathrooms : null,
                'amenityFeature' => $amenities,
            ], fn ($value) => $value !== null),
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $exact ? $property->address : null,
                'postalCode' => $exact ? $property->postal_code : null,
                'addressLocality' => $property->city,
                'addressCountry' => $property->country,
            ], fn ($value) => filled($value)),
            'checkinTime' => $time('check_in_from'),
            'checkoutTime' => $time('check_out_until'),
            'knowsLanguage' => Languages::active(),
        ];

        if ($exact && $property->latitude !== null && $property->longitude !== null) {
            $rental['latitude'] = round((float) $property->latitude, 5);
            $rental['longitude'] = round((float) $property->longitude, 5);
        }

        if (($property->approved_reviews_count ?? 0) > 0) {
            $rental['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $property->approved_reviews_avg_rating, 1),
                'reviewCount' => (int) $property->approved_reviews_count,
                'bestRating' => 5,
            ];
        }

        return array_filter($rental, fn ($value) => $value !== null);
    }

    /**
     * The trail from the home page to this page.
     *
     * @param  array<string, string>  $trail  name => address, from the home page
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $trail): array
    {
        $position = 0;

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($trail)->map(fn (string $url, string $name) => [
                '@type' => 'ListItem',
                'position' => ++$position,
                'name' => $name,
                'item' => $url,
            ])->values()->all(),
        ];
    }

    /**
     * The business behind the website and the website itself, for the home page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function business(): array
    {
        $name = (string) Setting::get('company', 'name', config('app.name'));
        $logo = Setting::get('branding', 'logo');

        return [
            array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $name,
                'url' => LocalizedUrl::home(Languages::default()),
                'logo' => $logo ? \Illuminate\Support\Facades\Storage::disk('public')->url($logo) : null,
                'email' => Setting::get('company', 'email') ?: null,
                'telephone' => Setting::get('company', 'phone') ?: null,
                'sameAs' => array_values(Property::master()->socialLinks()) ?: null,
            ], fn ($value) => $value !== null),
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $name,
                'url' => LocalizedUrl::home(Languages::default()),
                'inLanguage' => Languages::active(),
            ],
        ];
    }
}
