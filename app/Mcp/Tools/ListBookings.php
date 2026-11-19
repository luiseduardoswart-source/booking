<?php

namespace App\Mcp\Tools;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Support\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_bookings')]
#[Description('Lists bookings, newest arrival first, optionally for one accommodation, a status or arrivals in a period. Amounts are in the booking\'s currency in whole units. For one booking with contact details and payments use get_booking.')]
#[IsReadOnly]
class ListBookings extends BookingTool
{
    private const MAX = 100;

    protected function permission(): ?array
    {
        return ['bookings', Access::VIEW];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'accommodation' => $this->accommodationField($schema),
            'status' => $schema->string()->enum(array_column(BookingStatus::cases(), 'value'))->description('Only bookings with this status.'),
            'arrival_from' => $schema->string()->format('date')->description('Only arrivals on or after this date (YYYY-MM-DD).'),
            'arrival_to' => $schema->string()->format('date')->description('Only arrivals on or before this date (YYYY-MM-DD).'),
            'limit' => $schema->integer()->min(1)->max(self::MAX)->description('At most this many bookings. Default 25.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'arrival_from' => ['nullable', 'date_format:Y-m-d'],
            'arrival_to' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX],
        ]);

        $property = filled($request->get('accommodation')) ? $this->accommodation($user, $request->get('accommodation')) : null;

        $bookings = Access::restrict(Booking::query(), $user)
            ->with('property:id,name')
            ->when($property, fn ($query) => $query->where('property_id', $property->id))
            ->when($request->get('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->get('arrival_from'), fn ($query, $date) => $query->whereDate('arrival_date', '>=', $date))
            ->when($request->get('arrival_to'), fn ($query, $date) => $query->whereDate('arrival_date', '<=', $date))
            ->orderByDesc('arrival_date')
            ->limit((int) $request->get('limit', 25))
            ->get()
            ->map(fn (Booking $booking) => self::summary($booking))
            ->all();

        if ($bookings === []) {
            return Response::text('No bookings found.');
        }

        return Response::structured(['bookings' => $bookings]);
    }

    /** @return array<string, mixed> */
    public static function summary(Booking $booking): array
    {
        return [
            'booking_number' => $booking->booking_number,
            'accommodation' => $booking->property?->name,
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'guest_name' => $booking->guest_name,
            'arrival' => $booking->arrival_date->toDateString(),
            'departure' => $booking->departure_date->toDateString(),
            'guests' => $booking->guests,
            'total' => self::amount($booking->total_cents),
            'currency' => $booking->currency,
        ];
    }
}
