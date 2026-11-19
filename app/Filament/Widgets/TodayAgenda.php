<?php

namespace App\Filament\Widgets;

use App\Support\Access;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TodayAgenda extends TableWidget
{
    protected static ?int $sort = 2;

    /** Today's arrivals and departures with guest names: only with the right to see bookings. */
    public static function canView(): bool
    {
        return Access::may(auth()->user(), 'bookings', Access::VIEW);
    }

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('Agenda: arrivals and departures (today and tomorrow)');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Access::restrict(Booking::query())
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Paid->value])
                ->where(function (Builder $query) {
                    $query->whereBetween('arrival_date', [today(), today()->addDay()])
                        ->orWhereBetween('departure_date', [today(), today()->addDay()]);
                })
                ->orderBy('arrival_date'))
            ->paginated(false)
            ->columns([
                TextColumn::make('booking_number')
                    ->label(__('Number'))
                    ->url(fn (Booking $record) => route('filament.admin.resources.bookings.edit', $record)),
                TextColumn::make('property.name')
                    ->label(__('Accommodation')),
                TextColumn::make('guest_name')
                    ->label(__('Guest')),
                TextColumn::make('kind')
                    ->label('')
                    ->state(fn (Booking $record) => $record->arrival_date->isSameDay(today()) || $record->arrival_date->isSameDay(today()->addDay())
                        ? '→ '.__('Check-in').' '.($record->arrival_date->isToday() ? __('today') : __('tomorrow'))
                        : '← '.__('Check-out').' '.($record->departure_date->isToday() ? __('today') : __('tomorrow')))
                    ->badge()
                    ->color(fn (string $state) => str_starts_with($state, '→') ? 'success' : 'warning'),
                TextColumn::make('guests')
                    ->label(__('Guests')),
                TextColumn::make('payment_status')
                    ->label(__('Payment'))
                    ->badge(),
            ])
            ->emptyStateHeading(__('No arrivals or departures today or tomorrow'));
    }
}
