<?php

namespace App\Channels;

use App\Models\ChannelConnection;
use App\Support\OutboundUrl;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IcalChannelProvider implements ChannelProvider
{
    /** A calendar of a few years is some tens of kilobytes; more is no calendar. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly IcalParser $parser) {}

    public function name(): string
    {
        return 'ical';
    }

    /**
     * The address is typed in by a user, so it goes through OutboundUrl (no
     * internal addresses, a size limit), and an error only says what went
     * wrong, never what the other server answered.
     */
    public function pull(ChannelConnection $connection): array
    {
        try {
            OutboundUrl::check($connection->url);

            $response = Http::timeout(30)
                ->withOptions(OutboundUrl::options(self::MAX_BYTES))
                ->withHeaders(['User-Agent' => config('app.name').' PMS'])
                ->get($connection->url);
        } catch (\Throwable $e) {
            throw new RuntimeException(OutboundUrl::reason($e)?->getMessage() ?? __('The calendar could not be fetched.'));
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('The calendar could not be fetched (status :status).', ['status' => $response->status()]));
        }

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
