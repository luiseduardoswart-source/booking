<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Models\User;
use App\Support\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_accommodations')]
#[Description('Lists the accommodations you may see, with id, address, whether it is online, capacity and the base price per night. Start here: other tools need the id.')]
#[IsReadOnly]
class ListAccommodations extends BookingTool
{
    protected function permission(): ?array
    {
        return ['accommodations', Access::VIEW];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $accommodations = Access::restrict(Property::query(), $user)->orderBy('name')->get()
            ->map(fn (Property $property) => [
                ...$this->summaryOf($property),
                'online' => (bool) $property->active,
                'type' => $property->type,
                'city' => $property->city,
                'max_guests' => $property->max_guests,
                'bedrooms' => $property->bedrooms,
                'currency' => $property->priceCurrency(),
                'base_price_per_night' => self::amount($property->basePrice()),
            ])->all();

        if ($accommodations === []) {
            return Response::text('There are no accommodations you may see.');
        }

        return Response::structured(['accommodations' => $accommodations]);
    }
}
