<?php

namespace App\Engines\Pricing;

final readonly class QuoteLine
{
    public function __construct(
        public string $key,
        public string $label,
        public int $amountCents,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'amount_cents' => $this->amountCents,
        ];
    }
}
