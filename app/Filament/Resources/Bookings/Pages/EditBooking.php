<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\GuestAccess;
use App\Support\GuestDataEraser;
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

            // The right to be forgotten (GDPR). Owner only: it cannot be undone.
            Action::make('erase_guest')
                ->label(__('Erase guest data'))
                ->icon(Heroicon::OutlinedUserMinus)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Erase the data of this guest?'))
                ->modalDescription(fn (Booking $record) => __('Erases the name, address, phone, notes, form answers and signature of :email on every stay that is over or cancelled. Dates, amounts, payments and invoices stay, under the name "Anonymous". Coming stays are left as they are. This cannot be undone.', ['email' => $record->guest_email]))
                ->modalSubmitActionLabel(__('Erase'))
                ->authorize(fn (): bool => auth()->user()?->isAdministrator() ?? false)
                ->visible(fn (Booking $record): bool => (auth()->user()?->isAdministrator() ?? false) && $record->guest_name !== GuestDataEraser::ANONYMOUS)
                ->action(function (Booking $record): void {
                    ['erased' => $erased, 'kept' => $kept] = GuestDataEraser::eraseGuestOf($record);

                    Notification::make()
                        ->title(trans_choice('{0} Nothing erased: this guest only has coming stays.|{1} The data of 1 stay has been erased.|[2,*] The data of :count stays has been erased.', $erased))
                        ->body($kept > 0 ? trans_choice('{1} 1 coming stay keeps its data; erase it after the stay.|[2,*] :count coming stays keep their data; erase them after the stay.', $kept) : null)
                        ->success()
                        ->send();

                    $this->refreshFormData(['guest_name', 'guest_email', 'guest_phone', 'guest_address', 'guest_postal_code', 'guest_city', 'notes', 'internal_notes']);
                }),
        ];
    }
}
