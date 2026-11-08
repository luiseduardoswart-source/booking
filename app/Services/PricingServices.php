<?php

namespace App\Services;

use App\Models\Property;

/**
 * Which dynamic pricing service belongs to which property. A
 * new competitor is one line here plus one provider class.
 */
class PricingServices
{
    public const NO = 'none';

    /** @var array<string, class-string<DynamicPricingProvider>> */
    private const SERVICES = [
        'pricelabs' => PriceLabsProvider::class,
        'beyond' => BeyondProvider::class,
        'wheelhouse' => WheelhouseProvider::class,
    ];

    /** @return array<string, string> service => name */
    public static function options(): array
    {
        return [
            self::NO => __('None (manual prices)'),
            'pricelabs' => 'PriceLabs',
            'beyond' => 'Beyond',
            'wheelhouse' => 'Wheelhouse',
        ];
    }

    public function before(Property $property): DynamicPricingProvider
    {
        return app(self::SERVICES[$property->effective('pricing_service')] ?? NullDynamicPricingProvider::class);
    }
}
