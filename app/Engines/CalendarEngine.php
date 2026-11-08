<?php

namespace App\Engines;

use App\Enums\CalendarBlockType;
use App\Models\CalendarBlock;
use App\Models\Property;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The only module allowed to create and release calendar blocks.
 * The iCal import/export (phase 9) will also go through this engine.
 */
class CalendarEngine
{
    public function block(
        Property $property,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        CalendarBlockType $type = CalendarBlockType::Blocked,
        ?string $note = null,
        ?string $source = null,
        ?string $externalUid = null,
    ): CalendarBlock {
        $startDate = $startDate->startOfDay();
        $endDate = $endDate->startOfDay();

        if ($endDate->lessThan($startDate)) {
            throw new InvalidArgumentException('The end date must not be before the start date.');
        }

        return $property->calendarBlocks()->create([
            'type' => $type,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'note' => $note,
            'source' => $source,
            'external_uid' => $externalUid,
        ]);
    }

    public function release(CalendarBlock $block): void
    {
        $block->delete();
    }

    /**
     * Replace all blocks from an external source with a new set —
     * the basis for the iCal import in phase 9 (idempotent per source).
     *
     * @param  array<int, array{start_date: CarbonImmutable, end_date: CarbonImmutable, external_uid: string, note?: string|null}>  $entries
     */
    public function syncExternal(Property $property, string $source, array $entries): void
    {
        $keepUids = array_column($entries, 'external_uid');

        $property->calendarBlocks()
            ->where('source', $source)
            ->whereNotIn('external_uid', $keepUids)
            ->delete();

        foreach ($entries as $entry) {
            $property->calendarBlocks()->updateOrCreate(
                ['source' => $source, 'external_uid' => $entry['external_uid']],
                [
                    'type' => CalendarBlockType::External,
                    'start_date' => $entry['start_date'],
                    'end_date' => $entry['end_date'],
                    'note' => $entry['note'] ?? null,
                ],
            );
        }
    }
}
