<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Support\Access;
use App\Support\Exports\BookingExport;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->exportAction(),
        ];
    }

    /**
     * Excel file of the bookings arriving in a period, or the tourist tax per
     * accommodation and month. Only what the user may see; the amounts paid
     * only with the right to see payments.
     */
    private function exportAction(): Action
    {
        return Action::make('export')
            ->label(__('Export'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => Access::may(auth()->user(), 'bookings', Access::VIEW))
            ->modalHeading(__('Export bookings'))
            ->modalSubmitActionLabel(__('Download'))
            ->modalIcon(Heroicon::OutlinedArrowDownTray)
            ->fillForm(fn (): array => [
                'kind' => BookingExport::BOOKINGS,
                'from' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'until' => today()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                'with_cancelled' => false,
            ])
            ->schema([
                Radio::make('kind')
                    ->label(__('What'))
                    ->options([
                        BookingExport::BOOKINGS => __('Bookings and revenue'),
                        BookingExport::TOURIST_TAX => __('Tourist tax per accommodation and month'),
                    ])
                    ->live()
                    ->required(),
                DatePicker::make('from')
                    ->label(__('Arrival from'))
                    ->required(),
                DatePicker::make('until')
                    ->label(__('Arrival until'))
                    ->afterOrEqual('from')
                    ->required(),
                Toggle::make('with_cancelled')
                    ->label(__('Include cancelled bookings'))
                    ->visible(fn (Get $get): bool => $get('kind') === BookingExport::BOOKINGS),
            ])
            ->action(function (array $data): StreamedResponse {
                abort_unless(Access::may(auth()->user(), 'bookings', Access::VIEW), 403);

                $export = new BookingExport(
                    BookingResource::getEloquentQuery(),
                    CarbonImmutable::parse($data['from']),
                    CarbonImmutable::parse($data['until']),
                );

                return $export->download(
                    $data['kind'],
                    withCancelled: (bool) ($data['with_cancelled'] ?? false),
                    withPayments: Access::may(auth()->user(), 'payments', Access::VIEW),
                );
            });
    }
}
