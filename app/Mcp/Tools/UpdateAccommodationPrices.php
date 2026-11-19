<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Models\User;
use App\Support\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_accommodation_prices')]
#[Description('Changes the prices of one accommodation, as on its Prices tab in the admin. Amounts are in the accommodation\'s currency in whole units (145.5 = 145.50). Give only what changes. "reset" makes fields follow the default accommodation settings again. The weekend adjustment is either a fixed weekend price per night (weekend_kind fixed + weekend_price) or a percentage on the nightly price (weekend_kind percent + weekend_percentage, -50 to 100). Check the result with get_price_quote.')]
#[IsIdempotent]
class UpdateAccommodationPrices extends BookingTool
{
    /** Fields that hold a plain value instead of an amount. */
    private const OTHER = ['weekend_kind', 'weekend_percentage', 'tourist_tax_basis', 'included_guests'];

    private const MAX_AMOUNT = 1000000;

    protected function permission(): ?array
    {
        return ['accommodations', Access::EDIT];
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = ['accommodation' => $this->accommodationField($schema)->required()];

        foreach (array_keys(GetAccommodation::MONEY) as $field) {
            $fields[$field] = $schema->number()->min(0)->description('Amount in whole units of the accommodation\'s currency.');
        }

        return [
            ...$fields,
            'weekend_kind' => $schema->string()->enum([Property::WEEKEND_FIXED, Property::WEEKEND_PERCENT]),
            'weekend_percentage' => $schema->integer()->min(-50)->max(100)->description('E.g. 15 for 15% more on Friday and Saturday, -10 for 10% less.'),
            'tourist_tax_basis' => $schema->string()->enum([Property::TAX_PER_PERSON_NIGHT, Property::TAX_PER_NIGHT, Property::TAX_PER_STAY])
                ->description('How the tourist tax is charged: per person per night, per night, or once per stay.'),
            'included_guests' => $schema->integer()->min(1)->description('Guests included in the price; above that the extra guest fee applies.'),
            'reset' => $schema->array()->items($schema->string()->enum([...array_keys(GetAccommodation::MONEY), ...self::OTHER]))
                ->description('Fields that should follow the default accommodation settings again.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $allFields = [...array_keys(GetAccommodation::MONEY), ...self::OTHER];

        $data = $request->validate([
            ...array_fill_keys(array_keys(GetAccommodation::MONEY), ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_AMOUNT]),
            'weekend_kind' => ['nullable', Rule::in([Property::WEEKEND_FIXED, Property::WEEKEND_PERCENT])],
            'weekend_percentage' => ['nullable', 'integer', 'min:-50', 'max:100'],
            'tourist_tax_basis' => ['nullable', Rule::in([Property::TAX_PER_PERSON_NIGHT, Property::TAX_PER_NIGHT, Property::TAX_PER_STAY])],
            'included_guests' => ['nullable', 'integer', 'min:1'],
            'reset' => ['nullable', 'array'],
            'reset.*' => [Rule::in($allFields)],
        ]);

        $property = $this->accommodation($user, $request->get('accommodation'));
        $changes = [];

        foreach (GetAccommodation::MONEY as $field => $column) {
            if (isset($data[$field])) {
                $changes[$column] = (int) round((float) $data[$field] * 100);
            }
        }

        foreach (self::OTHER as $field) {
            if (isset($data[$field])) {
                $changes[$field] = $data[$field];
            }
        }

        foreach ($data['reset'] ?? [] as $field) {
            $changes[GetAccommodation::MONEY[$field] ?? $field] = null;
        }

        if ($changes === []) {
            throw new ToolFailure('Give at least one price to change, or fields in "reset".');
        }

        $property->fill($changes);

        // Same rule as the form: an own percentage adjustment needs its percentage.
        if ($property->weekend_kind === Property::WEEKEND_PERCENT && $property->weekend_percentage === null) {
            throw new ToolFailure('With weekend_kind percent, also give weekend_percentage.');
        }

        $property->save();

        return Response::structured([
            'changed' => array_keys($changes),
            'accommodation' => $this->summaryOf($property),
            'currency' => $property->priceCurrency(),
            'prices' => GetAccommodation::prices($property->refresh()),
        ]);
    }
}
