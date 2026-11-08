<?php

namespace App\Services;

use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * Active when no pricing service is chosen ("none"). Does nothing.
 */
class NullDynamicPricingProvider implements DynamicPricingProvider
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function pricesFor(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }
}
