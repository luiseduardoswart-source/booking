<?php

namespace App\Http\Controllers;

use App\Engines\PaymentEngine;
use App\Enums\IdentityStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Services\IdentityVerifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ID verification for guests (Stripe Identity): right after booking, from a
 * payment link while the check is still missing, or again after a failed
 * check. We never see the document — only the outcome. If verification is
 * required, it happens before payment.
 *
 * The signed link may name the payment the guest came for (?pay=balance):
 * after the check they go on to that payment, otherwise to the first one.
 */
class IdentityController extends Controller
{
    public function start(Request $request, Booking $booking, IdentityVerifier $verifier, PaymentEngine $payments): RedirectResponse|View
    {
        abort_unless($booking->property->idRequired(), 404);
        $pay = self::paymentType($request);

        if ($booking->identity_status === IdentityStatus::Verified) {
            return $this->continueToPayment($booking, $pay, $payments);
        }

        $url = $verifier->start($booking, $booking->signedLink('identity.return', self::parameters($booking, $pay)));

        return redirect()->away($url);
    }

    public function back(Request $request, Booking $booking, IdentityVerifier $verifier, PaymentEngine $payments): RedirectResponse|View
    {
        $verifier->refresh($booking);
        $booking->refresh();
        $pay = self::paymentType($request);

        if ($booking->identity_status === IdentityStatus::Verified) {
            return $this->continueToPayment($booking, $pay, $payments);
        }

        return view('site.verification', [
            'status' => $booking->identity_status,
            'property' => $booking->property,
            // A failed check can be done again right here: no email needed.
            'retryUrl' => $booking->signedLink('identity.start', self::parameters($booking, $pay)),
        ]);
    }

    /** The signed link to the ID check for a booking, remembering the payment the guest came for. */
    public static function parameters(Booking $booking, ?PaymentType $pay): array
    {
        return ['booking' => $booking, ...($pay === null ? [] : ['pay' => $pay->value])];
    }

    /** Only from the signed link, so it cannot be changed by hand. */
    private static function paymentType(Request $request): ?PaymentType
    {
        $type = PaymentType::tryFrom((string) $request->query('pay'));

        return $type === PaymentType::Refund ? null : $type;
    }

    /**
     * The payment the guest came for, or the first one. A still-valid link
     * (e.g. created via the webhook while the guest was away) is reused,
     * otherwise a new one is created.
     */
    private function continueToPayment(Booking $booking, ?PaymentType $pay, PaymentEngine $payments): RedirectResponse
    {
        return $pay === null
            ? redirect()->away($payments->checkoutOrConfirmationUrl($booking))
            : redirect()->to($booking->paymentLink($pay));
    }
}
