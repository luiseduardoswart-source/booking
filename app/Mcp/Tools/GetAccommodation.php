<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Models\User;
use App\Support\Access;
use App\Support\Languages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_accommodation')]
#[Description('Shows one accommodation: its texts per language, prices and stay rules. Prices are in the currency shown, in whole units (145.5 = 145.50). For every price "own" is what this accommodation sets itself and "applies" what guests pay; own = null means it follows the default accommodation settings.')]
#[IsReadOnly]
class GetAccommodation extends BookingTool
{
    /** The texts per language, as update_accommodation_text can change them. */
    public const TEXTS = ['name', 'short_description', 'description', 'house_rules'];

    /** The money fields, as update_accommodation_prices can change them. */
    public const MONEY = [
        'base_price' => 'base_price_cents',
        'weekend_price' => 'weekend_price_cents',
        'cleaning_fee' => 'cleaning_fee_cents',
        'tourist_tax' => 'tourist_tax_cents',
        'extra_guest_fee' => 'extra_guest_fee_cents',
        'pet_fee' => 'pet_fee_cents',
    ];

    protected function permission(): ?array
    {
        return ['accommodations', Access::VIEW];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'accommodation' => $this->accommodationField($schema)->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $property = $this->accommodation($user, $request->get('accommodation'));

        return Response::structured([
            ...$this->summaryOf($property),
            'online' => (bool) $property->active,
            'type' => $property->type,
            'address' => trim(implode(', ', array_filter([$property->address, $property->postal_code, $property->city, $property->country]))),
            'registration_number' => $property->registration_number,
            'capacity' => [
                'max_guests' => $property->max_guests,
                'bedrooms' => $property->bedrooms,
                'beds' => $property->beds,
                'bathrooms' => $property->bathrooms,
            ],
            'source_language' => Languages::SOURCE,
            'languages' => Languages::available(),
            'texts' => self::texts($property),
            'currency' => $property->priceCurrency(),
            'prices' => self::prices($property),
            'stay' => [
                'min_nights' => $property->effective('min_stay_nights'),
                'max_nights' => $property->effective('max_stay_nights'),
                'check_in_from' => $property->effective('check_in_from'),
                'check_in_until' => $property->effective('check_in_until'),
                'check_out_until' => $property->effective('check_out_until'),
                'pets_allowed' => (bool) $property->effective('pets_allowed'),
            ],
            // The house rules as guests see them (the own text is in texts.house_rules).
            'house_rules' => array_map(
                fn (array $rule) => $rule['text'].($rule['note'] ? " ({$rule['note']})" : ''),
                $property->houseRuleLines(times: false),
            ),
            'amenities' => $property->amenities()->pluck('name')->all(),
            'photos' => $property->gallery()->count(),
        ]);
    }

    /**
     * Per text: the source language from the column itself, every other
     * language from its translation (null = shows the source text).
     *
     * @return array<string, array<string, ?string>>
     */
    public static function texts(Property $property): array
    {
        return collect(self::TEXTS)->mapWithKeys(fn (string $field) => [$field => self::textPerLanguage($property, $field)])->all();
    }

    /** @return array<string, mixed> */
    public static function prices(Property $property): array
    {
        $money = collect(self::MONEY)->mapWithKeys(fn (string $column, string $key) => [
            $key => [
                'own' => self::amount($property->{$column}),
                'applies' => self::amount($property->effectiveAmount($column)),
            ],
        ])->all();

        $plain = fn (string $column) => ['own' => $property->{$column}, 'applies' => $property->effective($column)];

        return [
            ...$money,
            'weekend_kind' => $plain('weekend_kind'),
            'weekend_percentage' => $plain('weekend_percentage'),
            'tourist_tax_basis' => $plain('tourist_tax_basis'),
            'included_guests' => $plain('included_guests'),
        ];
    }
}
