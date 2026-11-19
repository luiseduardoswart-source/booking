<?php

namespace App\Mcp\Tools;

use App\Engines\AvailabilityEngine;
use App\Engines\Pricing\QuoteLine;
use App\Engines\PricingEngine;
use App\Models\User;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_price_quote')]
#[Description('Whether a stay is available and what it costs, exactly as a guest would see it on the website: nightly prices, cleaning fee, tourist tax and so on. Use it to check the effect of a price change.')]
#[IsReadOnly]
class GetPriceQuote extends BookingTool
{
    protected function permission(): ?array
    {
        return ['accommodations', Access::VIEW];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'accommodation' => $this->accommodationField($schema)->required(),
            'arrival' => $schema->string()->format('date')->description('Arrival date (YYYY-MM-DD).')->required(),
            'departure' => $schema->string()->format('date')->description('Departure date (YYYY-MM-DD).')->required(),
            'guests' => $schema->integer()->min(1)->description('Number of guests.')->required(),
            'pets' => $schema->integer()->min(0)->description('Number of pets. Default 0.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate([
            'arrival' => ['required', 'date_format:Y-m-d'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'guests' => ['required', 'integer', 'min:1'],
            'pets' => ['nullable', 'integer', 'min:0'],
        ]);

        $property = $this->accommodation($user, $request->get('accommodation'));
        $arrival = CarbonImmutable::parse($request->get('arrival'));
        $departure = CarbonImmutable::parse($request->get('departure'));

        $check = app(AvailabilityEngine::class)->check($property, $arrival, $departure);

        try {
            $quote = app(PricingEngine::class)->quote($property, $arrival, $departure, (int) $request->get('guests'), (int) $request->get('pets', 0));
        } catch (InvalidArgumentException $e) {
            throw new ToolFailure($e->getMessage());
        }

        return Response::structured([
            'accommodation' => $this->summaryOf($property),
            'available' => $check->available,
            'not_available_because' => $check->reasons,
            'nights' => $quote->nights,
            'currency' => $quote->currency,
            'lines' => array_map(fn (QuoteLine $line) => ['label' => $line->label, 'amount' => self::amount($line->amountCents)], $quote->lines),
            'total' => self::amount($quote->totalCents),
            'down_payment' => self::amount($quote->downPaymentCents),
            'deposit' => self::amount($quote->depositCents),
        ]);
    }
}
