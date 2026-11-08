<?php

namespace App\Channels;

use App\Models\ChannelConnection;
use Illuminate\Support\Facades\Http;

class IcalChannelProvider implements ChannelProvider
{
    public function __construct(private readonly IcalParser $parser) {}

    public function name(): string
    {
        return 'ical';
    }

    public function pull(ChannelConnection $connection): array
    {
        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => config('app.name').' PMS'])
            ->get($connection->url)
            ->throw();

        return $this->parser->parse($response->body());
    }

    public function capabilities(): array
    {
        return [
            'prices' => false,
            'guest_details' => false,
            'realtime' => false,
        ];
    }
}
