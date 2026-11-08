<?php

namespace App\Engines\Pricing;

use Carbon\CarbonImmutable;

final readonly class Quote
{
    /**
     * @param  QuoteLine[]  $lines
     */
    public function __construct(
        public CarbonImmutable $arrival,
        public CarbonImmutable $departure,
        public int $nights,
        public int $guests,
        public array $lines,
        public int $totalCents,
        public int $depositCents,          // deposit (separate from the total)
        public int $downPaymentCents,      // down payment
        public int $remainingCents,        // remaining payment
        public string $currency = 'EUR',   // currency of all amounts (ISO code)
    ) {}

    public function line(string $key): ?QuoteLine
    {
        foreach ($this->lines as $line) {
            if ($line->key === $key) {
                return $line;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'arrival' => $this->arrival->toDateString(),
            'departure' => $this->departure->toDateString(),
            'nights' => $this->nights,
            'guests' => $this->guests,
            'lines' => array_map(fn (QuoteLine $line) => $line->toArray(), $this->lines),
            'total_cents' => $this->totalCents,
            'deposit_cents' => $this->depositCents,
            'down_payment_cents' => $this->downPaymentCents,
            'remaining_cents' => $this->remainingCents,
            'currency' => $this->currency,
        ];
    }
}
