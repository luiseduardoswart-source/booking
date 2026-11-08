<?php

namespace App\Http\Controllers;

use App\Engines\PaymentEngine;
use App\Enums\IdentityStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Services\IdentityVerifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;

/**
 * ID verification for guests (Stripe Identity), via the signed link in the
 * confirmation email. We never see the document — only the outcome.
 * If verification is required, it happens before payment.
 */
class IdentityController extends Controller
{
    public function start(Booking $booking, IdentityVerifier $verifier, PaymentEngine $payments): RedirectResponse|View
    {
        abort_unless($booking->property->idRequired(), 404);

        if ($booking->identity_status === IdentityStatus::Verified) {
            return $this->continueToPayment($booking, $payments);
        }

        $url = $verifier->start($booking, URL::signedRoute('identity.return', $booking));

        return redirect()->away($url);
    }

    public function back(Booking $booking, IdentityVerifier $verifier, PaymentEngine $payments): RedirectResponse|View
    {
        $verifier->refresh($booking);
        $booking->refresh();

        if ($booking->identity_status === IdentityStatus::Verified) {
            return $this->continueToPayment($booking, $payments);
        }

        return view('site.verification', ['status' => $booking->identity_status, 'property' => $booking->property]);
    }

    /**
     * A still-valid deposit link (e.g. created via the webhook while the
     * guest was away) is reused, otherwise a new one is created.
     */
    private function continueToPayment(Booking $booking, PaymentEngine $payments): RedirectResponse
    {
        return redirect()->away($payments->checkoutOrConfirmationUrl($booking));
    }
}
