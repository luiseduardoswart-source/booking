<?php

namespace App\Services;

/**
 * Answer from a dynamic pricing service: price per night in
 * cents, and the currency in which the service gives those prices (null = the
 * service does not say; then the property's currency applies).
 */
final readonly class DynamicPrices
{
    /**
     * @param  array<string, int>  $prices  date (Y-m-d) => price in cents
     */
    public function __construct(
        public array $prices,
        public ?string $currency = null,
    ) {}

    public static function no(): self
    {
        return new self([]);
    }
}
