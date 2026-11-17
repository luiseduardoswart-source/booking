<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Cache;

/**
 * Fake provider for tests and for clicking through the payment flow locally without
 * a Mollie/Stripe account: the checkout is a test page of its own where you choose
 * whether the payment succeeds, fails or is cancelled.
 *
 * The status lives in the cache so it is also kept between requests
 * (needed in the browser); within one process (tests) the array is enough.
 */
class FakeProvider implements PaymentProvider
{
    /** @var array<string, ProviderPaymentStatus> */
    private array $statuses = [];

    public array $refunds = [];

    public function name(): string
    {
        return 'fake';
    }

    /** Never real money. */
    public function isTestMode(): bool
    {
        return true;
    }

    public function supportsCurrency(string $currency): bool
    {
        return true;
    }

    /** The fake checkout knows no amount other than ours. */
    public function amountIsCorrect(Payment $payment): bool
    {
        return true;
    }

    public function create(Payment $payment, string $redirectUrl): ProviderPaymentResult
    {
        // Fixed ID based on the payment: stays the same across requests.
        $id = 'fake_'.$payment->id;
        $this->setStatus($id, ProviderPaymentStatus::Open);

        return new ProviderPaymentResult($id, route('payment.test', $payment));
    }

    public function status(Payment $payment): ProviderPaymentStatus
    {
        $id = (string) $payment->provider_payment_id;

        return $this->statuses[$id]
            ?? Cache::get("fake_payment_status.{$id}")
            ?? ProviderPaymentStatus::Open;
    }

    public function refund(Payment $payment, int $amountCents): string
    {
        $this->refunds[] = ['payment' => $payment->provider_payment_id, 'amount_cents' => $amountCents];

        return 'refund_'.count($this->refunds);
    }

    public function cancel(Payment $payment): void
    {
        $this->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Canceled);
    }

    public function setStatus(string $providerPaymentId, ProviderPaymentStatus $status): void
    {
        $this->statuses[$providerPaymentId] = $status;
        Cache::put("fake_payment_status.{$providerPaymentId}", $status, now()->addDay());
    }
}
