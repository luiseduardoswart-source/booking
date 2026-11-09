<?php

namespace App\Http\Controllers;

use App\Engines\AvailabilityEngine;
use App\Engines\PricingEngine;
use App\Models\Property;
use App\Support\Money;
use App\Support\Slugs;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function home(Request $request, AvailabilityEngine $availability, PricingEngine $pricing): View|RedirectResponse
    {
        // One accommodation? Then the main domain shows the property itself
        // (no redirect — the URL simply stays "/"). Can be turned off via
        // admin → Settings → Website.
        if (\App\Models\Setting::get('site', 'single_accommodation_direct', '1') !== '0'
            && Property::query()->where('active', true)->count() === 1) {
            return $this->show(Property::query()->where('active', true)->first());
        }

        $validated = $request->validate([
            'arrival' => ['nullable', 'date'],
            'departure' => ['nullable', 'date', 'after:arrival', 'required_with:arrival'],
            'guests' => ['nullable', 'integer', 'min:1'],
            'pets' => ['nullable', 'integer', 'min:0'],
            'where' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
            'price_max' => ['nullable', 'integer', 'min:1'],
            'amenity' => ['nullable', 'integer'],
            'bedrooms' => ['nullable', 'integer', 'min:1'],
            'bathrooms' => ['nullable', 'integer', 'min:1'],
        ]);

        $guests = (int) ($validated['guests'] ?? 0);
        $pets = (int) ($validated['pets'] ?? 0);
        $arrival = isset($validated['arrival']) ? CarbonImmutable::parse($validated['arrival']) : null;
        $departure = isset($validated['departure']) ? CarbonImmutable::parse($validated['departure']) : null;

        $properties = Property::query()
            ->where('active', true)
            ->when($guests > 0, fn ($query) => $query->where('max_guests', '>=', $guests))
            ->when(filled($validated['where'] ?? null), function ($query) use ($validated) {
                $search = '%'.trim($validated['where']).'%';
                $query->where(fn ($sub) => $sub
                    ->where('name', 'like', $search)
                    ->orWhere('name_translations', 'like', $search)
                    ->orWhere('city', 'like', $search)
                    ->orWhere('address', 'like', $search));
            })
            ->when(filled($validated['type'] ?? null), fn ($query) => $query->where('type', $validated['type']))
            ->when(filled($validated['bedrooms'] ?? null), fn ($query) => $query->where('bedrooms', '>=', (int) $validated['bedrooms']))
            ->when(filled($validated['bathrooms'] ?? null), fn ($query) => $query->where('bathrooms', '>=', (int) $validated['bathrooms']))
            ->when(filled($validated['amenity'] ?? null), fn ($query) => $query->whereHas('amenities', fn ($sub) => $sub->whereKey((int) $validated['amenity'])))
            ->with('media')
            ->withCount('approvedReviews')
            ->withAvg('approvedReviews', 'rating')
            ->orderBy('name')
            ->get();

        // Price filter on the effective base price (with master fallback). The
        // maximum is in the default currency; each accommodation converts.
        if (filled($validated['price_max'] ?? null)) {
            $maxCents = (int) $validated['price_max'] * 100;
            $defaultCurrency = Money::defaultCurrency();
            $properties = $properties
                ->filter(fn (Property $property) => Money::convert($property->basePrice(), $property->priceCurrency(), $defaultCurrency) <= $maxCents)
                ->values();
        }

        // With pets, only accommodations where they are welcome (own choice or the default).
        if ($pets > 0) {
            $default = Property::master()->petsAllowed(); // once, not per accommodation
            $properties = $properties->filter(fn (Property $property) => $property->pets_allowed ?? $default)->values();
        }

        // Availability and total price for the chosen period.
        $totals = [];

        if ($arrival !== null && $departure !== null) {
            $properties = $properties->filter(
                fn (Property $property) => $availability->isAvailable($property, $arrival, $departure)
            )->values();

            foreach ($properties as $property) {
                try {
                    $totals[$property->id] = $pricing
                        ->quote($property, $arrival, $departure, max($guests, 1), $pets)
                        ->totalCents;
                } catch (\InvalidArgumentException) {
                    // no price to show; card falls back to "per night"
                }
            }
        }

        // Map data for the "explore the map" view (only properties
        // with coordinates; compact price for the price label on the map).
        $mapData = $properties
            ->filter(fn (Property $property) => $property->publicCoordinates(Property::MAP_OVERVIEW) !== null)
            ->map(fn (Property $property) => [
                'name' => $property->localized('name'),
                // Approximately located accommodations do not give away their spot here either.
                ...$property->publicCoordinates(Property::MAP_OVERVIEW),
                'url' => route('properties.show', array_merge(['property' => $property->slugFor()], array_filter([
                    'arrival' => $arrival?->toDateString(),
                    'departure' => $departure?->toDateString(),
                    'guests' => $guests ?: null,
                ]))),
                'price' => Money::compact($property->basePrice(), $property->priceCurrency()),
                'price_full' => Money::format($property->basePrice(), $property->priceCurrency()),
                'city' => collect([$property->city, $property->country ? \App\Support\Countries::name($property->country) : null])
                    ->filter()->implode(', '),
                'photo' => $property->getFirstMediaUrl('photos', 'large') ?: null,
            ])
            ->values();

        return view('site.home', [
            'properties' => $properties,
            'totals' => $totals,
            'nights' => ($arrival && $departure) ? (int) $arrival->diffInDays($departure) : null,
            'maxGuests' => (int) (Property::query()->where('active', true)->max('max_guests') ?: 16),
            // Filters and map belong to a site with multiple accommodations.
            'multipleListings' => Property::query()->where('active', true)->count() > 1,
            'mapData' => $mapData,
            'typeOptions' => \App\Models\PropertyType::options(),
            'priceSteps' => self::priceSteps(),
            // By name in the visitor's language, not by the English name in the database.
            'amenityOptions' => \App\Models\Amenity::query()->get()
                ->mapWithKeys(fn (\App\Models\Amenity $amenity) => [$amenity->id => $amenity->localizedName()])
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->all(),
            'filters' => [
                'arrival' => $arrival?->toDateString(),
                'departure' => $departure?->toDateString(),
                'guests' => $guests ?: null,
                'where' => $validated['where'] ?? null,
                'type' => $validated['type'] ?? null,
                'price_max' => $validated['price_max'] ?? null,
                'amenity' => $validated['amenity'] ?? null,
                'bedrooms' => $validated['bedrooms'] ?? null,
                'bathrooms' => $validated['bathrooms'] ?? null,
            ],
        ]);
    }

    /** Steps of the price filter in euros; converted for another default currency. */
    private const PRICE_STEPS_EURO = [100, 150, 200, 300, 500];

    /**
     * The maxima of the price filter, in whole units of the default currency,
     * rounded to two digits (e.g. ¥16,000 instead of ¥16,342).
     *
     * @return array<int, int>
     */
    private static function priceSteps(): array
    {
        $currency = Money::defaultCurrency();

        return array_map(function (int $euro) use ($currency): int {
            $amount = Money::convert($euro * 100, 'EUR', $currency) / 100;
            $order = 10 ** max(0, (int) floor(log10(max($amount, 1))) - 1);

            return (int) (round($amount / $order) * $order);
        }, self::PRICE_STEPS_EURO);
    }

    public function show(Property $property): View|RedirectResponse
    {
        abort_unless($property->active, 404);

        // On the address of another language: redirect to that of the current language.
        if (request()->routeIs('properties.show') && request()->segment(1) !== $property->slugFor()) {
            return redirect(Slugs::addressInLanguage(request()->fullUrl(), app()->getLocale()));
        }

        \App\Models\ListingEvent::record('page_view', $property);

        return view('site.properties.show', [
            'property' => $property
                ->load(['media', 'amenities', 'hosts.media', 'approvedReviews'])
                ->loadCount('approvedReviews')
                ->loadAvg('approvedReviews', 'rating'),
        ]);
    }
}
