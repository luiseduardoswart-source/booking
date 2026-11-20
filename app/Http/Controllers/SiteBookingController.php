<?php

namespace App\Http\Controllers;

use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Engines\Exceptions\UnavailableForBookingException;
use App\Engines\PaymentEngine;
use App\Engines\PricingEngine;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\Form;
use App\Models\Property;
use App\Support\FormAnswers;
use App\Support\Signature;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

class SiteBookingController extends Controller
{
    /** Wrong coupon codes a visitor may enter per WRONG_COUPON_SECONDS. */
    private const WRONG_COUPONS = 5;

    private const WRONG_COUPON_SECONDS = 600;

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
        [$coupon, $couponError] = $this->coupon($request, $property, $arrival, $validated['coupon'] ?? null);

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
     * The coupon code entered on the booking page, with the message for the
     * guest when it cannot be used. Wrong codes count per visitor: after
     * WRONG_COUPONS wrong ones no code is looked up for a while, so nobody
     * can find valid codes by trying them out. A good code does not count.
     *
     * @return array{0: ?Coupon, 1: ?string}
     */
    private function coupon(Request $request, Property $property, CarbonImmutable $arrival, ?string $code): array
    {
        if (blank($code)) {
            return [null, null];
        }

        $tries = 'wrong-coupons:'.$request->ip();

        if (RateLimiter::tooManyAttempts($tries, self::WRONG_COUPONS)) {
            return [null, __('Too many wrong coupon codes. Try again in :minutes minutes.', [
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($tries) / 60)),
            ])];
        }

        $coupon = Coupon::search($code);
        $error = $coupon === null ? __('This coupon code does not exist.') : $coupon->invalidBecause($property, $arrival);

        if ($error !== null) {
            RateLimiter::hit($tries, self::WRONG_COUPON_SECONDS);

            return [null, $error];
        }

        return [$coupon, null];
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
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\p{Cc}]*$/u'],
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

        // Made on the site: the dates are held while the guest pays. Bookings
        // from the admin or the API get no hold and never expire.
        $booking->update(['hold_until' => now()->addMinutes(Booking::HOLD_MINUTES)]);

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
