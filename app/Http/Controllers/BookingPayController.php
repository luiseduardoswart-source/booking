<?php

namespace App\Http\Controllers;

use App\Engines\Exceptions\NothingToPayException;
use App\Engines\PaymentEngine;
use App\Enums\PaymentType;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * Signed payment link from the emails: creates a payment on the fly
 * with the provider and sends the guest to the checkout.
 */
class BookingPayController extends Controller
{
    public function __invoke(Booking $booking, string $type, PaymentEngine $engine): RedirectResponse
    {
        $paymentType = PaymentType::tryFrom($type);

        if ($paymentType === null || $paymentType === PaymentType::Refund) {
            abort(404);
        }

        // ID check required and not done yet: that first, then on to this payment.
        if ($booking->identityStillMissingBeforePayment()) {
            return redirect()->to($booking->signedLink('identity.start', IdentityController::parameters($booking, $paymentType)));
        }

        try {
            $payment = $engine->createPayment($booking, $paymentType);
        } catch (NothingToPayException) {
            // Nothing (more) to pay: show the status of the last payment.
            $lastPayment = $booking->payments()->latest()->first();

            if ($lastPayment !== null) {
                return redirect()->to($lastPayment->returnUrl());
            }

            abort(404);
        } catch (Throwable $e) {
            // The provider cannot make the checkout right now: explain it and
            // let the guest try again, instead of an error page.
            report($e);

            return redirect()->to(PaymentEngine::unavailableUrl($booking));
        }

        return redirect()->away($payment->checkout_url);
    }
}
