<?php

namespace App\Http\Controllers;

use App\Engines\PaymentEngine;
use App\Models\Payment;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Fake payment page to click through the full booking and payment flow
 * locally without a Mollie/Stripe account.
 *
 * SECURITY: only works while the fake provider is selected. If a real
 * provider is configured, this page simply does not exist — otherwise
 * someone could mark a payment as "paid".
 */
class TestCheckoutController extends Controller
{
    private function guard(): void
    {
        abort_unless(
            Setting::get('payments', 'provider', 'mollie') === 'fake'
            && app(PaymentProvider::class) instanceof FakeProvider,
            404
        );
    }

    public function show(Payment $payment): View
    {
        $this->guard();

        return view('payments.test-checkout', [
            'payment' => $payment,
            'booking' => $payment->booking,
        ]);
    }

    public function process(Payment $payment, string $outcome, PaymentEngine $engine): RedirectResponse
    {
        $this->guard();

        $status = match ($outcome) {
            'paid' => ProviderPaymentStatus::Paid,
            'failed' => ProviderPaymentStatus::Failed,
            'cancelled' => ProviderPaymentStatus::Canceled,
            default => abort(404),
        };

        /** @var FakeProvider $provider */
        $provider = app(PaymentProvider::class);
        $provider->setStatus((string) $payment->provider_payment_id, $status);

        // Same route as a real provider: back to the payment status
        // page, which fetches and processes the status.
        return redirect()->to($payment->returnUrl());
    }
}
