<?php

namespace App\Http\Controllers\Api;

use App\Engines\AvailabilityEngine;
use App\Engines\PricingEngine;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Occupied nights (and optionally the price per night) for the
 * availability calendar on the website.
 */
class UnavailableDatesController extends Controller
{
    public function __invoke(Request $request, Property $property, AvailabilityEngine $availability, PricingEngine $pricing): JsonResponse
    {
        abort_unless($property->active, 404);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = $request->query('from')
            ? CarbonImmutable::parse($request->query('from'))
            : CarbonImmutable::today();

        $to = $request->query('to')
            ? CarbonImmutable::parse($request->query('to'))
            : $from->addMonths(18);

        // Every night is computed: at most two years at once, starting at
        // most a year back (a calendar never shows more).
        $from = $from->max(CarbonImmutable::today()->subYear());
        $to = $to->min($from->addMonths(24));

        // Booking horizon: dates beyond the horizon cannot be booked.
        $windowMonths = $property->effective('booking_window_months');
        $maxDate = $windowMonths !== null
            ? CarbonImmutable::today()->addMonths((int) $windowMonths)->toDateString()
            : null;

        $payload = [
            'dates' => $availability->unavailableDates($property, $from, $to),
            'min_nights' => $availability->minNightsMap($property, $from, $to),
            'max_date' => $maxDate,
            'min_nights_text' => __('Minimum :nights nights'),
        ];

        // Include the price per night if enabled (per property or globally).
        if ($property->pricesInCalendar()) {
            $payload['prices'] = collect($pricing->nightlyPriceMap($property, $from, $to))
                ->map(fn (int $cents) => Money::compact($cents, $property->priceCurrency()))
                ->all();
        }

        return response()->json($payload);
    }
}
