<?php

namespace App\Services;

use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * Fake pricing service for tests and local development without a PriceLabs account.
 * Prices can be set per (property, date); "not configured" by default.
 */
class FakeDynamicPricingProvider implements DynamicPricingProvider
{
    public bool $configured = true;

    /** @var array<string, int> date (Y-m-d) => cents */
    public array $prices = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function pricesFor(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = [];

        foreach ($this->prices as $date => $cents) {
            $d = CarbonImmutable::parse($date);
            if ($d->betweenIncluded($from, $to)) {
                $result[$d->toDateString()] = $cents;
            }
        }

        return $result;
    }
}
