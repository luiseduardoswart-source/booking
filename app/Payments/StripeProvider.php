<?php

namespace App\Payments;

use App\Models\Payment;
use App\Models\Setting;
use RuntimeException;
use Stripe\StripeClient;

class StripeProvider implements PaymentProvider
{
    private ?StripeClient $client = null;

    public function name(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return filled(Setting::get('payments', 'stripe_secret_key'));
    }

    /** Stripe test keys are sk_test_ (or restricted rk_test_), live keys sk_live_. */
    public function isTestMode(): bool
    {
        return (bool) preg_match('/^(sk|rk)_test_/', (string) Setting::get('payments', 'stripe_secret_key'));
    }

    /** Currencies without decimals: the amount is in whole units (500 = ¥500). */
    private const WITHOUT_DECIMALS = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
        'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Without decimals, but passed as two decimals that are always 00. */
    private const WHOLE_UNITS_WITH_TWO_DECIMALS = ['ISK', 'UGX'];

    /** Currencies with three decimals; the last digit must be 0. */
    private const THREE_DECIMALS = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    public function supportsCurrency(string $currency): bool
    {
        // Stripe settles in almost any currency; without an exchange rate we do not know it.
        return preg_match('/^[A-Z]{3}$/', strtoupper($currency)) === 1;
    }

    /**
     * The amount in the smallest unit as Stripe expects it. We
     * always store hundredths; for some currencies Stripe wants whole
     * units or thousandths.
     */
    public static function amount(int $cents, string $currency): int
    {
        $currency = strtoupper($currency);

        return match (true) {
            in_array($currency, self::WITHOUT_DECIMALS, true) => (int) round($cents / 100),
            in_array($currency, self::WHOLE_UNITS_WITH_TWO_DECIMALS, true) => (int) round($cents / 100) * 100,
            in_array($currency, self::THREE_DECIMALS, true) => $cents * 10,
            default => $cents,
        };
    }

    /** Back from Stripe's smallest unit to the hundredths we store: the reverse of amount(). */
    public static function cents(int $amount, string $currency): int
    {
        $currency = strtoupper($currency);

        return match (true) {
            in_array($currency, self::WITHOUT_DECIMALS, true) => $amount * 100,
            in_array($currency, self::THREE_DECIMALS, true) => (int) round($amount / 10),
            default => $amount,
        };
    }

    public function create(Payment $payment, string $redirectUrl): ProviderPaymentResult
    {
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payment->booking->currency),
                    'unit_amount' => self::amount($payment->amount_cents, $payment->booking->currency),
                    'product_data' => [
                        'name' => $payment->description ?? __('Payment — :number', ['number' => $payment->booking->booking_number], $payment->booking->locale),
                    ],
                ],
            ]],
            'success_url' => $redirectUrl,
            'cancel_url' => $redirectUrl,
            'metadata' => [
                'payment_id' => $payment->id,
                'booking_number' => $payment->booking->booking_number,
            ],
        ]);

        return new ProviderPaymentResult(
            providerPaymentId: $session->id,
            checkoutUrl: $session->url,
        );
    }

    public function status(Payment $payment): ProviderPaymentStatus
    {
        $session = $this->client()->checkout->sessions->retrieve($payment->provider_payment_id);

        return match (true) {
            $session->payment_status === 'paid' => ProviderPaymentStatus::Paid,
            $session->status === 'expired' => ProviderPaymentStatus::Expired,
            default => ProviderPaymentStatus::Open,
        };
    }

    public function refund(Payment $payment, int $amountCents): string
    {
        $session = $this->client()->checkout->sessions->retrieve($payment->provider_payment_id);

        $refund = $this->client()->refunds->create([
            'payment_intent' => $session->payment_intent,
            'amount' => self::amount($amountCents, $payment->booking->currency),
        ]);

        return $refund->id;
    }

    /** Dispute statuses in which Stripe has taken the money from the owner's balance. */
    private const DISPUTE_TOOK_THE_MONEY = ['needs_response', 'under_review', 'lost'];

    /**
     * The charge of the checkout says what was refunded; a dispute (the
     * guest's bank reversing the payment) is listed separately. A dispute
     * the owner won, or an inquiry without money taken, does not count.
     */
    public function returnedCents(Payment $payment): int
    {
        $session = $this->client()->checkout->sessions->retrieve($payment->provider_payment_id, ['expand' => ['payment_intent.latest_charge']]);
        $charge = $session->payment_intent?->latest_charge;

        if (! is_object($charge)) {
            return 0;
        }

        $returned = (int) $charge->amount_refunded;

        if ($charge->disputed) {
            foreach ($this->client()->disputes->all(['charge' => $charge->id, 'limit' => 10])->data as $dispute) {
                if (in_array($dispute->status, self::DISPUTE_TOOK_THE_MONEY, true)) {
                    $returned += (int) $dispute->amount;
                }
            }
        }

        return self::cents($returned, $payment->booking->currency);
    }

    /**
     * Our Checkout Session that belongs to a Stripe payment (pi_...), or null.
     * Events about a refund or a dispute name the payment, not the session.
     */
    public function sessionIdFor(string $paymentIntentId): ?string
    {
        return $this->client()->checkout->sessions->all(['payment_intent' => $paymentIntentId, 'limit' => 1])->data[0]->id ?? null;
    }

    public function amountIsCorrect(Payment $payment): bool
    {
        $session = $this->client()->checkout->sessions->retrieve($payment->provider_payment_id);

        return strtolower((string) $session->currency) === strtolower($payment->booking->currency)
            && (int) $session->amount_total === self::amount($payment->amount_cents, $payment->booking->currency);
    }

    public function cancel(Payment $payment): void
    {
        $this->client()->checkout->sessions->expire($payment->provider_payment_id);
    }

    private function client(): StripeClient
    {
        if ($this->client === null) {
            $apiKey = Setting::get('payments', 'stripe_secret_key');

            if ($apiKey === null || $apiKey === '') {
                throw new RuntimeException(
                    'No Stripe secret key is set (setting payments/stripe_secret_key).'
                );
            }

            $this->client = new StripeClient($apiKey);
        }

        return $this->client;
    }
}
