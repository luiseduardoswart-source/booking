<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * Abstraction layer for payment providers. Mollie is the first implementation;
 * Stripe can be added later without changing the PaymentEngine.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Does this provider work with a test key (no real money)? Stored on
     * every payment, so test bookings can be cleared safely before going live.
     */
    public function isTestMode(): bool;

    /**
     * Create the payment with the provider and return the external id and the
     * checkout URL the guest must be sent to. The provider handles its own
     * webhook configuration.
     */
    public function create(Payment $payment, string $redirectUrl): ProviderPaymentResult;

    /**
     * Fetch the current status from the provider (source of truth).
     */
    public function status(Payment $payment): ProviderPaymentStatus;

    /**
     * Refund (part of) a paid payment.
     * Returns the external refund id.
     */
    public function refund(Payment $payment, int $amountCents): string;

    /**
     * Expire an open payment so the link can no longer be paid. Throws an
     * error if that fails.
     */
    public function cancel(Payment $payment): void;

    /**
     * Can this provider charge in this currency (ISO code)? The admin warns
     * when an accommodation has a currency that cannot be charged.
     */
    public function supportsCurrency(string $currency): bool;

    /**
     * Do the amount and currency the provider reports for this payment match
     * what we created? Extra check before a payment counts as paid; compared
     * in the provider's own unit.
     */
    public function amountIsCorrect(Payment $payment): bool;
}
