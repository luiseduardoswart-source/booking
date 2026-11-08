<?php

namespace App\Services;

use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * Abstraction for an external dynamic pricing service (e.g. PriceLabs).
 * That way another provider later is just one new implementation — as with
 * the payment providers and the translation/verification services.
 */
interface DynamicPricingProvider
{
    /**
     * Is the service configured (key present and enabled)?
     */
    public function isConfigured(): bool;

    /**
     * Recommended prices per night for a property in a period.
     * Returns date (Y-m-d) => price in cents. Empty array if nothing is
     * available (or the property has no connection).
     *
     * @return array<string, int>
     */
    public function pricesFor(Property $property, CarbonImmutable $from, CarbonImmutable $to): array;
}
