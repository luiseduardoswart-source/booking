<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Support\Access;
use App\Support\ListingStatistics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_statistics')]
#[Description('Visits of the website over the last days (today included): page views, unique visitors, price requests and bookings, with the conversion (bookings per page view, in %). For all accommodations or one. The same figures as the Statistics page.')]
#[IsReadOnly]
class GetStatistics extends BookingTool
{
    protected function permission(): ?array
    {
        return ['statistics', Access::VIEW];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'accommodation' => $this->accommodationField($schema)->description('Only this accommodation (id, address or name). Empty = all.'),
            'days' => $schema->integer()->min(1)->max(365)->description('Number of days back. Default 30.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);

        $property = filled($request->get('accommodation')) ? $this->accommodation($user, $request->get('accommodation')) : null;
        $days = (int) $request->get('days', ListingStatistics::DAYS);

        return Response::structured([
            'accommodation' => $property ? $this->summaryOf($property) : 'all',
            'days' => $days,
            ...ListingStatistics::summary($property?->id, $days),
        ]);
    }
}
