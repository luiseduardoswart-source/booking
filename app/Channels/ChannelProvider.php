<?php

namespace App\Channels;

use App\Models\ChannelConnection;
use Carbon\CarbonImmutable;

/**
 * Abstraction layer for channel synchronisation. iCal is the first implementation;
 * channel-manager or platform APIs will come alongside it later without changing
 * the CalendarEngine or the sync command.
 */
interface ChannelProvider
{
    public function name(): string;

    /**
     * Fetch the external occupancy.
     *
     * @return array<int, array{external_uid: string, start_date: CarbonImmutable, end_date: CarbonImmutable, note?: string|null}>
     */
    public function pull(ChannelConnection $connection): array;

    /**
     * What can this provider do? iCal can only read busy/free; API providers
     * will later also be able to push prices and supply guest details.
     *
     * @return array{prices: bool, guest_details: bool, realtime: bool}
     */
    public function capabilities(): array;
}
