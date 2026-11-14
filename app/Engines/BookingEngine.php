<?php

namespace App\Engines;

use App\Engines\Exceptions\InvalidStatusTransitionException;
use App\Engines\Exceptions\UnavailableForBookingException;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Support\Languages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates bookings and guides them through their lifecycle. Uses the
 * AvailabilityEngine and PricingEngine — no own checks or calculations.
 *
 * Double-booking prevention: the final availability check happens INSIDE a
 * database transaction in which the property row is locked
 * (SELECT ... FOR UPDATE). Two simultaneous attempts for the same period are
 * thus serialised: the second waits for the lock and then sees the first
 * booking.
 */
class BookingEngine
{
    public function __construct(
        private readonly AvailabilityEngine $availability,
        private readonly PricingEngine $pricing,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, address?: string|null, postal_code?: string|null, city?: string|null, country?: string|null}  $guest
     */
    public function create(
        Property $property,
        CarbonImmutable $arrival,
        CarbonImmutable $departure,
        int $guests,
        array $guest,
        ?string $notes = null,
        ?array $guestBreakdown = null, // ['adults' => 2, 'children' => 1, 'infants' => 0, 'pets' => 0]
        ?string $acceptedTerms = null, // snapshot of the accepted terms
        ?string $couponCode = null,    // coupon code; invalid = silently ignored
        ?\App\Support\Signature $signature = null, // under the rental terms, if they apply
        ?array $formAnswers = null, // answers to the own fields of the booking form (FormAnswers)
    ): Booking {
        $booking = DB::transaction(function () use ($property, $arrival, $departure, $guests, $guest, $notes, $guestBreakdown, $acceptedTerms, $couponCode, $signature, $formAnswers) {
            // Lock the property row so simultaneous attempts wait for each other.
            $property = Property::query()
                ->whereKey($property->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $result = $this->availability->check($property, $arrival, $departure);

            if (! $result->available) {
                throw new UnavailableForBookingException($result->reasons);
            }

            // Coupon code: only apply if it is (still) valid — inside the
            // transaction, so max_uses cannot be exceeded.
            $coupon = \App\Models\Coupon::search($couponCode);

            if ($coupon !== null && $coupon->invalidBecause($property, $arrival) !== null) {
                $coupon = null;
            }

            $quote = $this->pricing->quote($property, $arrival, $departure, $guests, (int) ($guestBreakdown['pets'] ?? 0), $coupon);

            $booking = Booking::create([
                'booking_number' => 'temporary', // final number follows based on the id
                'property_id' => $property->id,
                'status' => BookingStatus::New,
                'payment_status' => PaymentStatus::Unpaid,
                'guest_name' => $guest['name'],
                'guest_email' => $guest['email'],
                'guest_phone' => $guest['phone'] ?? null,
                'guest_address' => $guest['address'] ?? null,
                'guest_postal_code' => $guest['postal_code'] ?? null,
                'guest_city' => $guest['city'] ?? null,
                'guest_country' => $guest['country'] ?? 'NL',
                // The guest's language: never an admin language that is switched off on the site.
                'locale' => in_array(app()->getLocale(), Languages::active(), true)
                    ? app()->getLocale()
                    : Languages::default(),
                'arrival_date' => $quote->arrival,
                'departure_date' => $quote->departure,
                'guests' => $guests,
                'adults' => $guestBreakdown['adults'] ?? null,
                'children' => $guestBreakdown['children'] ?? null,
                'infants' => $guestBreakdown['infants'] ?? null,
                'pets' => $guestBreakdown['pets'] ?? null,
                'notes' => $notes,
                'form_answers' => $formAnswers ?: null,
                'terms_accepted_at' => $acceptedTerms !== null ? now() : null,
                'terms_snapshot' => $acceptedTerms,
                'price_snapshot' => $quote->toArray(),
                'total_cents' => $quote->totalCents,
                'currency' => $quote->currency,
                'down_payment_cents' => $quote->downPaymentCents,
                'deposit_cents' => $quote->depositCents,
                'coupon_code' => $coupon?->code,
            ]);

            // Number based on the database id: guaranteed unique and ascending.
            $booking->booking_number = sprintf('BK-%d-%04d', now()->year, $booking->id);
            $booking->save();

            // Record the use of the coupon code (inside the transaction).
            $coupon?->increment('used_count');

            // Last in the transaction: there is never a booking without the
            // required signature. If saving the booking still fails afterwards,
            // at most an image is left behind that nothing refers to.
            $signature?->record($booking);

            return $booking;
        });

        // No confirmation mail here: a guest on the site gets it once the
        // payment has succeeded (PaymentEngine), so after any ID check that
        // comes before it. A booking made in the admin sends it itself.

        return $booking;
    }

    public function transition(Booking $booking, BookingStatus $to): Booking
    {
        if (! $booking->status->canTransitionTo($to)) {
            throw new InvalidStatusTransitionException($booking->status, $to);
        }

        $booking->status = $to;
        $booking->save();

        return $booking;
    }

    public function cancel(Booking $booking, ?string $reason = null): Booking
    {
        $booking = $this->transition($booking, BookingStatus::Cancelled);

        if ($reason !== null) {
            $booking->internal_notes = trim(($booking->internal_notes ?? '')."\nCancelled: {$reason}");
            $booking->save();
        }

        return $booking;
    }
}
