<?php

namespace App\Services;

use App\Enums\IdentityStatus;
use App\Models\Booking;
use App\Models\Setting;
use RuntimeException;
use Stripe\Identity\VerificationSession;
use Stripe\StripeClient;

class StripeIdentityVerifier implements IdentityVerifier
{
    private ?StripeClient $client = null;

    public function start(Booking $booking, string $returnUrl): string
    {
        $session = $this->client()->identity->verificationSessions->create([
            'type' => 'document',
            'metadata' => ['booking_number' => $booking->booking_number],
            'return_url' => $returnUrl,
        ]);

        $booking->update([
            'identity_session_id' => $session->id,
            'identity_status' => IdentityStatus::Pending,
        ]);

        return $session->url;
    }

    public function refresh(Booking $booking): void
    {
        if ($booking->identity_session_id === null) {
            return;
        }

        $session = $this->client()->identity->verificationSessions->retrieve($booking->identity_session_id);

        $booking->update(['identity_status' => $this->statusOf($session)]);
    }

    /**
     * Stripe has no "rejected" status: a failed check is at
     * requires_input with a last_error. Without an error the guest is not done yet.
     */
    public function statusOf(VerificationSession $session): IdentityStatus
    {
        return match (true) {
            $session->status === 'verified' => IdentityStatus::Verified,
            $session->status === 'canceled' => IdentityStatus::Failed,
            $session->status === 'requires_input' && $session->last_error !== null => IdentityStatus::Failed,
            default => IdentityStatus::Pending,
        };
    }

    private function client(): StripeClient
    {
        if ($this->client === null) {
            $apiKey = Setting::get('payments', 'stripe_secret_key');

            if ($apiKey === null || $apiKey === '') {
                throw new RuntimeException(
                    'ID verification requires a Stripe secret key (setting payments/stripe_secret_key).'
                );
            }

            $this->client = new StripeClient($apiKey);
        }

        return $this->client;
    }
}
