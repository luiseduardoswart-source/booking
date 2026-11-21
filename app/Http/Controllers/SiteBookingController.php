<?php

namespace App\Http\Controllers;

use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Engines\Exceptions\InvalidCouponException;
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
            'departure' => Booking::departureRule(),
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

        $bookingForm = Form::fixed(Form::BOOKING);

        return view('site.bookings.create', [
            'property' => $property,
            'bookingForm' => $bookingForm,
            // Per fixed field: required, optional or hidden on this accommodation.
            'fieldModes' => $bookingForm->modesAt($property),
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

        if (($refusal = $this->takeCouponTry($request)) !== null) {
            return [null, $refusal];
        }

        try {
            $coupon = Coupon::usable($code, $property, $arrival);
        } catch (InvalidCouponException $e) {
            return [null, $e->getMessage()]; // the try stays counted
        }

        $this->giveCouponTryBack($request);

        return [$coupon, null];
    }

    /**
     * Looking a code up first takes one of the visitor's tries; a good code
     * gives it back. Counting only after a wrong answer would let a burst of
     * requests sent at once all be looked up. Returns the message when the
     * tries are used up.
     */
    private function takeCouponTry(Request $request): ?string
    {
        $tries = self::couponTries($request);

        if (! RateLimiter::tooManyAttempts($tries, self::WRONG_COUPONS)) {
            if (RateLimiter::increment($tries, self::WRONG_COUPON_SECONDS) <= self::WRONG_COUPONS) {
                return null;
            }

            $this->giveCouponTryBack($request); // another request took the last one
        }

        return __('Too many wrong coupon codes. Try again in :minutes minutes.', [
            'minutes' => max(1, (int) ceil(RateLimiter::availableIn($tries) / 60)),
        ]);
    }

    private function giveCouponTryBack(Request $request): void
    {
        RateLimiter::decrement(self::couponTries($request), self::WRONG_COUPON_SECONDS);
    }

    private static function couponTries(Request $request): string
    {
        return 'wrong-coupons:'.$request->ip();
    }

    /**
     * Step 2: create the booking and redirect to the payment environment.
     */
    public function store(Request $request, Property $property, BookingEngine $bookings, PaymentEngine $payments): RedirectResponse
    {
        abort_unless($property->active, 404);

        $bookingForm = Form::fixed(Form::BOOKING);
        [$ownRules, $ownNames] = FormAnswers::rules($bookingForm);
        // The owner chooses per field: required, optional or hidden (Forms → Booking form).
        $phone = $bookingForm->modeAt('phone', $property);

        $validated = $request->validate([
            'arrival' => Booking::arrivalRule(),
            'departure' => Booking::departureRule(),
            'guests' => ['required', 'integer', 'min:1'],
            'adults' => ['nullable', 'integer', 'min:1'],
            'children' => ['nullable', 'integer', 'min:0'],
            'infants' => $property->babyRule(),
            'pets' => $property->petsRule(),
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\p{Cc}]*$/u'],
            'email' => ['required', 'email', 'max:255'],
            // Required as well where its last four digits are the door code.
            'phone' => $phone === Form::HIDDEN ? ['exclude'] : Booking::phoneRule($phone === Form::REQUIRED),
            'address' => $bookingForm->ruleAt('address', $property, ['string', 'max:255']),
            'postal_code' => $bookingForm->ruleAt('postal_code', $property, ['string', 'max:20']),
            'city' => $bookingForm->ruleAt('city', $property, ['string', 'max:100']),
            'notes' => $bookingForm->ruleAt('notes', $property, ['string', 'max:2000']),
            'coupon' => ['nullable', 'string', 'max:50'],
            'terms' => ['accepted'],
            ...$ownRules,
        ], [
            'terms.accepted' => __('You must agree to the conditions in order to book.'),
            ...($property->phoneRequired() ? [
                'phone.required' => __('Your phone number is needed: its last four digits are the code of the door.'),
                'phone.regex' => __('Your phone number is needed: its last four digits are the code of the door.'),
            ] : ['phone.regex' => __('Enter a valid phone number.')]),
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

        // When the rental terms apply, or at every booking if the owner chose
        // so, the guest signs the conditions. Check before creating: no
        // booking without it.
        $signature = $property->signatureNeeded($nights)
            ? Signature::offRequest($request, $validated['name'])
            : null;

        // The booking looks the coupon code up as well (and the checkout shows
        // whether it worked): the same tries as on the page.
        $withCoupon = filled($validated['coupon'] ?? null);

        if ($withCoupon && ($refusal = $this->takeCouponTry($request)) !== null) {
            return back()->withInput()->withErrors(['coupon' => $refusal]);
        }

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
        } catch (InvalidCouponException $e) {
            // No booking at the full price behind the guest's back; the try stays counted.
            return back()->withInput()->withErrors(['coupon' => $e->getMessage()]);
        } catch (UnavailableForBookingException $e) {
            $withCoupon && $this->giveCouponTryBack($request); // never got to the code

            return back()->withInput()->withErrors(['availability' => implode(' ', $e->reasons)]);
        } catch (InvalidArgumentException $e) {
            $withCoupon && $this->giveCouponTryBack($request);

            return back()->withInput()->withErrors(['availability' => $e->getMessage()]);
        }

        $withCoupon && $this->giveCouponTryBack($request);

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
     * The checkout could not be made (signed URL): the booking is held but
     * not confirmed, and the guest can try again.
     */
    public function paymentUnavailable(Booking $booking): View
    {
        return view('payments.unavailable', [
            'booking' => $booking,
            'retryUrl' => $booking->paymentLink(\App\Enums\PaymentType::DownPayment),
        ]);
    }

    /**
     * Confirmation page (signed URL, so it cannot be guessed).
     */
    public function confirmation(Booking $booking): View
    {
        return view('site.bookings.confirmation', ['booking' => $booking]);
    }
}
