<?php

namespace App\Http\Controllers;

use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Engines\Exceptions\UnavailableForBookingException;
use App\Engines\PaymentEngine;
use App\Engines\PricingEngine;
use App\Models\Booking;
use App\Models\Form;
use App\Models\Property;
use App\Support\FormAnswers;
use App\Support\Signature;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

class SiteBookingController extends Controller
{
    /**
     * Step 1: summary + guest details form.
     */
    public function create(
        Request $request,
        Property $property,
        AvailabilityEngine $availability,
        PricingEngine $pricing,
    ): View|RedirectResponse {
        abort_unless($property->active, 404);

        $validated = $request->validate([
            'arrival' => Booking::arrivalRule(),
            'departure' => ['required', 'date', 'after:arrival'],
            'guests' => ['required', 'integer', 'min:1'],
            'adults' => ['nullable', 'integer', 'min:1'],
            'children' => ['nullable', 'integer', 'min:0'],
            'infants' => $property->babyRule(),
            'pets' => $property->petsRule(),
            'coupon' => ['nullable', 'string', 'max:50'],
        ], [...$property->guestsNotifications(), ...Booking::arrivalNotification()]);

        $arrival = CarbonImmutable::parse($validated['arrival']);
        $departure = CarbonImmutable::parse($validated['departure']);

        $result = $availability->check($property, $arrival, $departure);

        if (! $result->available) {
            return redirect()
                ->route('properties.show', $property->slugFor())
                ->withErrors(['availability' => implode(' ', $result->reasons)]);
        }

        // Coupon code: valid → included in the price; invalid → show a clear message.
        $couponError = null;
        $coupon = \App\Models\Coupon::search($validated['coupon'] ?? null);

        if (filled($validated['coupon'] ?? null) && $coupon === null) {
            $couponError = __('This coupon code does not exist.');
        } elseif ($coupon !== null && ($couponError = $coupon->invalidBecause($property, $arrival)) !== null) {
            $coupon = null;
        }

        try {
            $quote = $pricing->quote($property, $arrival, $departure, (int) $validated['guests'], (int) ($validated['pets'] ?? 0), $coupon);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('properties.show', $property->slugFor())
                ->withErrors(['availability' => $e->getMessage()]);
        }

        return view('site.bookings.create', [
            'property' => $property,
            'bookingForm' => Form::fixed(Form::BOOKING),
            'quote' => $quote,
            'coupon' => $coupon,
            'couponError' => $couponError,
            'breakdown' => [
                'adults' => $validated['adults'] ?? null,
                'children' => $validated['children'] ?? 0,
                'infants' => $validated['infants'] ?? 0,
                'pets' => $validated['pets'] ?? 0,
            ],
        ]);
    }

    /**
     * Step 2: create the booking and redirect to the payment environment.
     */
    public function store(Request $request, Property $property, BookingEngine $bookings, PaymentEngine $payments): RedirectResponse
    {
        abort_unless($property->active, 404);

        $bookingForm = Form::fixed(Form::BOOKING);
        [$ownRules, $ownNames] = FormAnswers::rules($bookingForm);

        $validated = $request->validate([
            'arrival' => Booking::arrivalRule(),
            'departure' => ['required', 'date', 'after:arrival'],
            'guests' => ['required', 'integer', 'min:1'],
            'adults' => ['nullable', 'integer', 'min:1'],
            'children' => ['nullable', 'integer', 'min:0'],
            'infants' => $property->babyRule(),
            'pets' => $property->petsRule(),
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            // The last four digits are the door code: then a phone number is needed.
            'phone' => $property->phoneRequired()
                ? ['required', 'string', 'max:50', 'regex:/(\D*\d){4}/']
                : ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'coupon' => ['nullable', 'string', 'max:50'],
            'terms' => ['accepted'],
            ...$ownRules,
        ], [
            'terms.accepted' => __('You must agree to the conditions in order to book.'),
            'phone.required' => __('Your phone number is needed: its last four digits are the code of the door.'),
            'phone.regex' => __('Your phone number is needed: its last four digits are the code of the door.'),
            ...$property->guestsNotifications(),
            ...Booking::arrivalNotification(),
        ], $ownNames);

        // With a given breakdown the guest count is always adults +
        // children (infants and pets do not count towards the maximum).
        $guests = isset($validated['adults'])
            ? (int) $validated['adults'] + (int) ($validated['children'] ?? 0)
            : (int) $validated['guests'];

        $arrival = CarbonImmutable::parse($validated['arrival']);
        $departure = CarbonImmutable::parse($validated['departure']);
        $nights = (int) $arrival->diffInDays($departure);

        // If the rental terms apply to this stay, the guest must sign them.
        // Check before creating: no booking without it.
        $signature = $property->rentalTermsApply($nights)
            ? Signature::offRequest($request, $validated['name'])
            : null;

        try {
            $booking = $bookings->create(
                property: $property,
                arrival: $arrival,
                departure: $departure,
                guests: $guests,
                guest: [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'postal_code' => $validated['postal_code'] ?? null,
                    'city' => $validated['city'] ?? null,
                ],
                notes: $validated['notes'] ?? null,
                acceptedTerms: $property->bookingTermsText($nights),
                signature: $signature,
                couponCode: $validated['coupon'] ?? null,
                formAnswers: FormAnswers::collect($bookingForm, (array) ($validated[FormAnswers::INPUT] ?? [])),
                guestBreakdown: isset($validated['adults']) ? [
                    'adults' => (int) $validated['adults'],
                    'children' => (int) ($validated['children'] ?? 0),
                    'infants' => (int) ($validated['infants'] ?? 0),
                    'pets' => (int) ($validated['pets'] ?? 0),
                ] : null,
            );
        } catch (UnavailableForBookingException $e) {
            return back()->withInput()->withErrors(['availability' => implode(' ', $e->reasons)]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['availability' => $e->getMessage()]);
        }

        \App\Models\ListingEvent::record('booking', $property);

        // If ID verification is required, complete that first and only then
        // proceed to payment. That way a guest who does not want to verify
        // does not have to pay anything yet.
        if ($booking->identityStillMissingBeforePayment()) {
            return redirect()->to(URL::signedRoute('identity.start', $booking));
        }

        return redirect()->away($payments->checkoutOrConfirmationUrl($booking));
    }

    /**
     * Confirmation page (signed URL, so it cannot be guessed).
     */
    public function confirmation(Booking $booking): View
    {
        return view('site.bookings.confirmation', ['booking' => $booking]);
    }
}
