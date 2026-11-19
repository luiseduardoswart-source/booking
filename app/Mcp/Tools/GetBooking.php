<?php

namespace App\Mcp\Tools;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\User;
use App\Support\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_booking')]
#[Description('One booking in full: the guest\'s contact details, the stay, the price lines and what has been paid. Contains personal data: only use it for the question asked.')]
#[IsReadOnly]
class GetBooking extends BookingTool
{
    protected function permission(): ?array
    {
        return ['bookings', Access::VIEW];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'booking_number' => $schema->string()->description('The booking number, e.g. BK-2026-0012, from list_bookings.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $number = trim((string) $request->get('booking_number'));

        $booking = Access::restrict(Booking::query(), $user)->with('property:id,name')->where('booking_number', $number)->first()
            ?? throw new ToolFailure("No booking \"{$number}\" found.");

        $payments = $booking->paymentOverview();

        return Response::structured([
            ...ListBookings::summary($booking),
            'admin_url' => BookingResource::canEdit($booking) ? BookingResource::getUrl('edit', ['record' => $booking]) : null,
            'guest' => [
                'name' => $booking->guest_name,
                'email' => $booking->guest_email,
                'phone' => $booking->guest_phone,
                'city' => $booking->guest_city,
                'country' => $booking->guest_country,
                'language' => $booking->locale,
            ],
            'party' => [
                'adults' => $booking->adults,
                'children' => $booking->children,
                'infants' => $booking->infants,
                'pets' => $booking->pets,
            ],
            'notes_from_guest' => $booking->notes,
            'price_lines' => collect($booking->price_snapshot['lines'] ?? [])
                ->map(fn ($line) => ['label' => $line['label'] ?? '', 'amount' => self::amount((int) ($line['amount_cents'] ?? 0))])
                ->all(),
            'paid' => self::amount($payments['paid']),
            'refunded' => self::amount($payments['refunded']),
            'outstanding' => self::amount($payments['outstanding']),
            'booked_at' => $booking->created_at?->toIso8601String(),
        ]);
    }
}
