<?php

namespace App\Mcp\Tools;

use App\Enums\CalendarBlockType;
use App\Models\Booking;
use App\Models\CalendarBlock;
use App\Models\User;
use App\Support\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('block_dates')]
#[Description('Blocks nights of an accommodation so guests cannot book them, as in Calendar in the admin. The first and last night are the dates you would sleep there: blocking 10 to 12 June keeps the nights of 10, 11 and 12 June free of bookings, and a guest may still leave on 10 June. Existing bookings stay; the answer names any that overlap.')]
class BlockDates extends BookingTool
{
    protected function permission(): ?array
    {
        return ['calendar', Access::CREATE];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'accommodation' => $this->accommodationField($schema)->required(),
            'first_night' => $schema->string()->format('date')->description('First blocked night (YYYY-MM-DD).')->required(),
            'last_night' => $schema->string()->format('date')->description('Last blocked night (YYYY-MM-DD), the same as first_night for one night.')->required(),
            'type' => $schema->string()->enum([CalendarBlockType::Blocked->value, CalendarBlockType::Maintenance->value])->description('Default: blocked.'),
            'note' => $schema->string()->max(500)->description('Why, for yourself (not shown to guests).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'first_night' => ['required', 'date_format:Y-m-d'],
            'last_night' => ['required', 'date_format:Y-m-d', 'after_or_equal:first_night'],
            'type' => ['nullable', Rule::in([CalendarBlockType::Blocked->value, CalendarBlockType::Maintenance->value])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $property = $this->accommodation($user, $request->get('accommodation'));

        $block = CalendarBlock::query()->create([
            'property_id' => $property->id,
            'type' => $data['type'] ?? CalendarBlockType::Blocked->value,
            'start_date' => $data['first_night'],
            'end_date' => $data['last_night'],
            'note' => $data['note'] ?? null,
        ]);

        // A booking overlaps when it has a night in the block: arrival on or
        // before the last night, departure after the first.
        $overlapping = Booking::query()
            ->where('property_id', $property->id)
            ->whereNotIn('status', Booking::INACTIVE_STATUSES)
            ->whereDate('arrival_date', '<=', $data['last_night'])
            ->whereDate('departure_date', '>', $data['first_night'])
            ->pluck('booking_number')
            ->all();

        return Response::structured([
            'block_id' => $block->id,
            'accommodation' => $this->summaryOf($property),
            'first_night' => $data['first_night'],
            'last_night' => $data['last_night'],
            'overlapping_bookings' => $overlapping,
        ]);
    }
}
