<?php

namespace App\Http\Controllers;

use App\Models\ListingEvent;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Records a photo view from the lightbox (via navigator.sendBeacon).
 * Deliberately minimal: validates that the photo belongs to the (active)
 * property and logs the event. Always responds with 204 (no content).
 */
class TrackingController extends Controller
{
    public function photo(Request $request): Response
    {
        $data = $request->validate([
            'property_id' => ['required', 'integer'],
            'media_id' => ['required', 'integer'],
        ]);

        $property = Property::find($data['property_id']);

        if ($property && $property->active && $property->media()->whereKey($data['media_id'])->exists()) {
            ListingEvent::record('photo_view', $property, ['media_id' => $data['media_id']]);
        }

        return response()->noContent();
    }
}
