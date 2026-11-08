<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Engines\BookingEngine;
use App\Engines\Exceptions\UnavailableForBookingException;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Manual bookings (e.g. by phone) also go through the BookingEngine,
 * so availability, price snapshot and locking always apply.
 */
class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(BookingEngine::class)->create(
                property: Property::findOrFail($data['property_id']),
                arrival: CarbonImmutable::parse($data['arrival_date']),
                departure: CarbonImmutable::parse($data['departure_date']),
                guests: (int) $data['guests'],
                guest: [
                    'name' => $data['guest_name'],
                    'email' => $data['guest_email'],
                    'phone' => $data['guest_phone'] ?? null,
                    'address' => $data['guest_address'] ?? null,
                    'postal_code' => $data['guest_postal_code'] ?? null,
                    'city' => $data['guest_city'] ?? null,
                    'country' => $data['guest_country'] ?? 'NL',
                ],
                notes: $data['notes'] ?? null,
            );
        } catch (UnavailableForBookingException $e) {
            Notification::make()
                ->title(__('Not available'))
                ->body(implode(' ', $e->reasons))
                ->danger()
                ->send();

            $this->halt();
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title(__('Booking not possible'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
