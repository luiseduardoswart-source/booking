<?php

namespace App\Http\Controllers\Api;

use App\Engines\AvailabilityEngine;
use App\Engines\PricingEngine;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Live availability + price for the booking widget. This is also the
 * first public API endpoint (Module 13): the website and external consumers
 * use exactly the same engines.
 */
class QuoteController extends Controller
{
    public function __invoke(
        Request $request,
        Property $property,
        AvailabilityEngine $availability,
        PricingEngine $pricing,
    ): JsonResponse {
        abort_unless($property->active, 404);

        $validated = $request->validate([
            'arrival' => Booking::arrivalRule(),
            'departure' => ['required', 'date', 'after:arrival'],
            'guests' => ['required', 'integer', 'min:1'],
            // Same rule as when booking: no pets where they are not allowed.
            'pets' => $property->petsRule(),
        ], [...$property->guestsNotifications(), ...Booking::arrivalNotification()]);

        $arrival = CarbonImmutable::parse($validated['arrival']);
        $departure = CarbonImmutable::parse($validated['departure']);

        $result = $availability->check($property, $arrival, $departure);

        if (! $result->available) {
            return response()->json([
                'available' => false,
                'reasons' => $result->reasons,
            ]);
        }

        try {
            $quote = $pricing->quote($property, $arrival, $departure, (int) $validated['guests'], (int) ($validated['pets'] ?? 0));
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'available' => false,
                'reasons' => [$e->getMessage()],
            ]);
        }

        \App\Models\ListingEvent::record('quote', $property);

        return response()->json([
            'available' => true,
            'quote' => $quote->toArray(),
        ]);
    }
}
