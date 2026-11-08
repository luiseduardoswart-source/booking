<?php

namespace App\Http\Controllers;

use App\Engines\PaymentEngine;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Payment;
use Illuminate\Contracts\View\View;

class PaymentReturnController extends Controller
{
    /**
     * The guest returns here after checkout. We sync directly with the
     * provider so the status is correct even if the webhook has not (yet)
     * arrived — essential in local development.
     */
    public function __invoke(Payment $payment, PaymentEngine $engine): View
    {
        if ($payment->provider_payment_id !== null && $payment->status === PaymentTransactionStatus::Open) {
            $payment = $engine->syncFromProvider($payment);
        }

        $booking = $payment->booking;

        return view('payments.return', [
            'payment' => $payment,
            'booking' => $booking,
            // Deposit right after booking: straight on to that separate payment.
            'depositLink' => $payment->status === PaymentTransactionStatus::Paid
                && $payment->type !== PaymentType::Deposit
                && $engine->depositDueNow($booking)
                    ? $booking->paymentLink(PaymentType::Deposit)
                    : null,
        ]);
    }
}
