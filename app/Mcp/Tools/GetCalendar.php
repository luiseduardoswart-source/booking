<?php

namespace App\Mcp\Tools;

use App\Engines\AvailabilityEngine;
use App\Enums\CalendarBlockType;
use App\Models\Booking;
use App\Models\CalendarBlock;
use App\Models\User;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_calendar')]
#[Description('The calendar of one accommodation for a period: which nights are taken, the blocked periods (with their id, for remove_date_block) and, if you may see bookings, the bookings in that period. A night is the date you sleep there; departing on the first night of a block is fine.')]
#[IsReadOnly]
class GetCalendar extends BookingTool
{
    /** At most this many days at once, so the answer stays readable. */
    private const MAX_DAYS = 400;

    protected function permission(): ?array
    {
        return ['calendar', Access::VIEW];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'accommodation' => $this->accommodationField($schema)->required(),
            'from' => $schema->string()->format('date')->description('First date (YYYY-MM-DD). Default: today.'),
            'to' => $schema->string()->format('date')->description('Last date (YYYY-MM-DD). Default: three months after "from".'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $property = $this->accommodation($user, $request->get('accommodation'));
        $from = $request->get('from') ? CarbonImmutable::parse($request->get('from')) : CarbonImmutable::today();
        $to = $request->get('to') ? CarbonImmutable::parse($request->get('to')) : $from->addMonths(3);

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new ToolFailure('Ask for at most '.self::MAX_DAYS.' days at once.');
        }

        $calendar = [
            'accommodation' => $this->summaryOf($property),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'taken_nights' => app(AvailabilityEngine::class)->unavailableDates($property, $from, $to),
            'blocks' => CalendarBlock::query()
                ->where('property_id', $property->id)
                ->whereDate('start_date', '<=', $to)
                ->whereDate('end_date', '>=', $from)
                ->orderBy('start_date')
                ->get()
                ->map(fn (CalendarBlock $block) => [
                    'id' => $block->id,
                    'type' => $block->type->value,
                    'first_night' => $block->start_date->toDateString(),
                    'last_night' => $block->end_date->toDateString(),
                    'note' => $block->note,
                    'from_sync' => $block->type === CalendarBlockType::External,
                ])->all(),
        ];

        if (Access::may($user, 'bookings', Access::VIEW)) {
            $calendar['bookings'] = Booking::query()
                ->where('property_id', $property->id)
                ->whereNotIn('status', Booking::INACTIVE_STATUSES)
                ->whereDate('arrival_date', '<=', $to)
                ->whereDate('departure_date', '>', $from)
                ->orderBy('arrival_date')
                ->get(['booking_number', 'status', 'guest_name', 'arrival_date', 'departure_date', 'guests'])
                ->map(fn (Booking $booking) => [
                    'booking_number' => $booking->booking_number,
                    'status' => $booking->status->value,
                    'guest_name' => $booking->guest_name,
                    'arrival' => $booking->arrival_date->toDateString(),
                    'departure' => $booking->departure_date->toDateString(),
                    'guests' => $booking->guests,
                ])->all();
        }

        return Response::structured($calendar);
    }
}
