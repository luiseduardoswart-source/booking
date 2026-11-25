<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\GuestRegistration;
use App\Models\Property;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * The details of travellers are kept no longer than the law of the
 * accommodation asks: the number of days after departure set per
 * accommodation. A cancelled stay never takes place, so its registrations
 * go at once.
 */
class PurgeGuestRegistrations extends Command
{
    protected $signature = 'registrations:purge';

    protected $description = 'Erases the registered travellers of stays that ended longer ago than the accommodation keeps them, and of cancelled stays';

    public function handle(): int
    {
        $removed = GuestRegistration::query()
            ->whereHas('booking', fn (Builder $booking) => $booking->where('status', BookingStatus::Cancelled->value))
            ->delete();

        Property::query()->each(function (Property $property) use (&$removed): void {
            $days = max(1, (int) $property->effective('guest_registration_keep_days'));

            $removed += GuestRegistration::query()
                ->whereHas('booking', fn (Builder $booking) => $booking
                    ->where('property_id', $property->id)
                    ->where('departure_date', '<', today()->subDays($days)))
                ->delete();
        });

        $this->info("{$removed} registered traveller(s) erased.");

        return self::SUCCESS;
    }
}
