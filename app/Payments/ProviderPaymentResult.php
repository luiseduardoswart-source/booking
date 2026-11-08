<?php

namespace App\Payments;

final readonly class ProviderPaymentResult
{
    public function __construct(
        public string $providerPaymentId,
        public string $checkoutUrl,
    ) {}
}
