<?php

namespace App\Payments;

use App\Models\Payment;
use App\Models\Setting;
use Mollie\Api\MollieApiClient;
use RuntimeException;

class MollieProvider implements PaymentProvider
{
    private ?MollieApiClient $client = null;

    public function name(): string
    {
        return 'mollie';
    }

    public function isConfigured(): bool
    {
        return filled(Setting::get('payments', 'mollie_api_key'));
    }

    /** Mollie test keys start with test_, live keys with live_. */
    public function isTestMode(): bool
    {
        return str_starts_with((string) Setting::get('payments', 'mollie_api_key'), 'test_');
    }

    /**
     * Currencies Mollie charges in, and those without decimals
     * (docs.mollie.com/docs/multicurrency). Which payment method works also
     * depends on the currency; Mollie decides that itself.
     */
    public const CURRENCIES = [
        'AED', 'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD',
        'HUF', 'ILS', 'ISK', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN',
        'RON', 'RUB', 'SEK', 'SGD', 'THB', 'TWD', 'UAH', 'USD', 'ZAR',
    ];

    private const WITHOUT_DECIMALS = ['ISK', 'JPY'];

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }

    /**
     * The amount the way Mollie wants it: the currency and a string with the
     * right number of decimals (yen none, euros two).
     *
     * @return array{currency: string, value: string}
     */
    public static function amount(int $cents, string $currency): array
    {
        $currency = strtoupper($currency);

        if (! in_array($currency, self::CURRENCIES, true)) {
            throw new RuntimeException(__('Mollie does not charge in :currency. Choose Stripe as payment provider or a different currency for this accommodation.', ['currency' => $currency]));
        }

        $decimals = in_array($currency, self::WITHOUT_DECIMALS, true) ? 0 : 2;

        return ['currency' => $currency, 'value' => number_format($cents / 100, $decimals, '.', '')];
    }

    public function create(Payment $payment, string $redirectUrl): ProviderPaymentResult
    {
        // Without a public https URL (local development) Mollie cannot call
        // the webhook; then we poll the status via the return page.
        $webhookUrl = str_starts_with(config('app.url'), 'https://')
            ? route('webhooks.mollie')
            : null;

        $data = [
            'amount' => self::amount($payment->amount_cents, $payment->booking->currency),
            'description' => $payment->description ?? __('Payment — :number', ['number' => $payment->booking->booking_number], $payment->booking->locale),
            'redirectUrl' => $redirectUrl,
            'metadata' => [
                'payment_id' => $payment->id,
                'booking_number' => $payment->booking->booking_number,
            ],
        ];

        if ($webhookUrl !== null) {
            $data['webhookUrl'] = $webhookUrl;
        }

        $molliePayment = $this->client()->payments->create($data);

        return new ProviderPaymentResult(
            providerPaymentId: $molliePayment->id,
            checkoutUrl: $molliePayment->getCheckoutUrl(),
        );
    }

    public function status(Payment $payment): ProviderPaymentStatus
    {
        $molliePayment = $this->client()->payments->get($payment->provider_payment_id);

        return match (true) {
            $molliePayment->isPaid() => ProviderPaymentStatus::Paid,
            $molliePayment->isFailed() => ProviderPaymentStatus::Failed,
            $molliePayment->isExpired() => ProviderPaymentStatus::Expired,
            $molliePayment->isCanceled() => ProviderPaymentStatus::Canceled,
            default => ProviderPaymentStatus::Open,
        };
    }

    public function refund(Payment $payment, int $amountCents): string
    {
        $molliePayment = $this->client()->payments->get($payment->provider_payment_id);

        $refund = $molliePayment->refund([
            'amount' => self::amount($amountCents, $payment->booking->currency),
        ]);

        return $refund->id;
    }

    /** Mollie reports both as totals on the payment, in whole units of the currency. */
    public function returnedCents(Payment $payment): int
    {
        $molliePayment = $this->client()->payments->get($payment->provider_payment_id);

        return (int) round(($molliePayment->getAmountRefunded() + $molliePayment->getAmountChargedBack()) * 100);
    }

    public function amountIsCorrect(Payment $payment): bool
    {
        $molliePayment = $this->client()->payments->get($payment->provider_payment_id);
        $expected = self::amount($payment->amount_cents, $payment->booking->currency);

        return strtoupper((string) $molliePayment->amount->currency) === $expected['currency']
            && (string) $molliePayment->amount->value === $expected['value'];
    }

    public function cancel(Payment $payment): void
    {
        $this->client()->payments->cancel($payment->provider_payment_id);
    }

    private function client(): MollieApiClient
    {
        if ($this->client === null) {
            $apiKey = Setting::get('payments', 'mollie_api_key');

            if ($apiKey === null || $apiKey === '') {
                throw new RuntimeException(
                    'No Mollie API key is set (setting payments/mollie_api_key).'
                );
            }

            $this->client = new MollieApiClient;
            $this->client->setApiKey($apiKey);
        }

        return $this->client;
    }
}
