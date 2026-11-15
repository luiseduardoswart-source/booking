<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\GuestAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The guest's "My bookings" on every device: e.g. a lost phone. The
            // link in the emails keeps working; a new login needs the mailbox.
            Action::make('guest_logout')
                ->label(__('Log the guest out of My bookings'))
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn (Booking $record) => __('Ends every login of :email on the site. The guest can log in again with a new link sent to that address.', ['email' => $record->guest_email]))
                ->authorize(fn (Booking $record): bool => BookingResource::canEdit($record))
                ->action(function (Booking $record): void {
                    GuestAccess::revoke((string) $record->guest_email);

                    Notification::make()->title(__('The guest has been logged out.'))->success()->send();
                }),
        ];
    }
}
