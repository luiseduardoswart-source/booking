<?php

namespace App\Http\Controllers\Api;

use App\Engines\BookingEngine;
use App\Engines\Exceptions\UnavailableForBookingException;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingApiResource;
use App\Models\Booking;
use App\Models\Property;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;

/**
 * Bookings via the API (Sanctum token required). External integrations use
 * exactly the same BookingEngine as the website and the admin, and the same
 * rights (App\Support\Access): the permission for bookings, and a host only
 * the bookings and accommodations of their own.
 */
class BookingApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(Access::may($request->user(), 'bookings', Access::VIEW), 403);

        $bookings = Access::restrict(Booking::query(), $request->user())
            ->when($request->query('property_id'), fn ($q, $id) => $q->where('property_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('arrival_date')
            ->paginate(25);

        return BookingApiResource::collection($bookings);
    }

    public function show(Request $request, Booking $booking): BookingApiResource
    {
        abort_unless(Access::may($request->user(), 'bookings', Access::VIEW), 403);
        abort_unless(Access::isOwn($booking, $request->user()), 404);

        return new BookingApiResource($booking);
    }

    public function store(Request $request, BookingEngine $engine): JsonResponse
    {
        abort_unless(Access::may($request->user(), 'bookings', Access::CREATE), 403);

        $validated = $request->validate([
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'arrival' => Booking::arrivalRule(),
            'departure' => ['required', 'date', 'after:arrival'],
            'guests' => ['required', 'integer', 'min:1'],
            'guest.name' => ['required', 'string', 'max:255'],
            'guest.email' => ['required', 'email', 'max:255'],
            'guest.phone' => ['nullable', 'string', 'max:50'],
            'guest.address' => ['nullable', 'string', 'max:255'],
            'guest.postal_code' => ['nullable', 'string', 'max:20'],
            'guest.city' => ['nullable', 'string', 'max:100'],
            'guest.country' => ['nullable', 'string', 'max:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], Booking::arrivalNotification());

        try {
            $booking = $engine->create(
                property: Access::restrict(Property::query(), $request->user())->findOrFail($validated['property_id']),
                arrival: CarbonImmutable::parse($validated['arrival']),
                departure: CarbonImmutable::parse($validated['departure']),
                guests: (int) $validated['guests'],
                guest: $validated['guest'],
                notes: $validated['notes'] ?? null,
            );
        } catch (UnavailableForBookingException $e) {
            return response()->json([
                'message' => 'Not available',
                'reasons' => $e->reasons,
            ], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new BookingApiResource($booking))
            ->response()
            ->setStatusCode(201);
    }
}
