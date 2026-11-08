<?php

namespace App\Services;

use App\Models\Booking;

/**
 * Abstraction for ID verification. Stripe Identity is the first implementation;
 * the guest does the verification at the provider — we never see the document,
 * only the outcome (GDPR-friendly).
 */
interface IdentityVerifier
{
    /**
     * Start (or resume) a verification session and return the URL where the
     * guest completes the verification.
     */
    public function start(Booking $booking, string $returnUrl): string;

    /**
     * Fetch the current status from the provider and update the booking.
     */
    public function refresh(Booking $booking): void;
}
