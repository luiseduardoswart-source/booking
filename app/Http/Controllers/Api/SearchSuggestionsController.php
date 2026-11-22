<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autocomplete for the "Where to" field: places and accommodation names that
 * match what the visitor types. Places fill the search field; an
 * accommodation links straight to its page.
 */
class SearchSuggestionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        if (mb_strlen($search) < 2) {
            return response()->json(['suggestions' => []]);
        }

        $like = '%'.$search.'%';

        $places = Property::query()
            ->where('active', true)
            ->whereNotNull('city')
            ->where('city', 'like', $like)
            ->orderBy('city')
            ->get(['city', 'country'])
            ->unique('city')
            ->take(5)
            ->map(fn (Property $property) => [
                'type' => 'city',
                'text' => $property->city,
                'detail' => $property->country ? \App\Support\Countries::name($property->country) : null,
            ])
            ->values();

        $accommodations = Property::query()
            ->where('active', true)
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('name_translations', 'like', $like))
            ->orderBy('name')
            ->limit(5)
            ->get(['name', 'name_translations', 'slug', 'slug_translations', 'city'])
            ->map(fn (Property $property) => [
                'type' => 'accommodation',
                'text' => $property->localized('name'),
                'detail' => $property->city,
                'url' => \App\Support\LocalizedUrl::of($property),
            ]);

        return response()->json([
            'suggestions' => $places->concat($accommodations)->take(7)->values(),
        ]);
    }
}
