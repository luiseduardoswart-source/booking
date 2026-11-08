<?php

namespace App\Filament\Resources\Properties\Tables;

use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Support\Switches;
use App\Models\Property;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PropertiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('photos')
                    ->label(__('Photo'))
                    ->collection('photos')
                    ->conversion('thumb')
                    ->limit(1),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->formatStateUsing(fn (?string $state) => \App\Models\PropertyType::nameFor($state))
                    ->badge(),
                TextColumn::make('city')
                    ->label(__('City'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('max_guests')
                    ->label(__('Max. guests'))
                    ->numeric()
                    ->sortable(),
                Switches::column('active', __('Active'), PropertyResource::class),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime('d-m-Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('calendar')
                    ->label(__('Calendar'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(fn (Property $record) => \App\Filament\Resources\Properties\PropertyResource::getUrl('edit', ['record' => $record]).'?tab=calendar'),
                Action::make('ical_url')
                    ->label(__('iCal URL'))
                    ->icon(Heroicon::OutlinedLink)
                    ->action(function (Property $record) {
                        Notification::make()
                            ->title('iCal export feed for '.$record->name)
                            ->body('Paste this URL into Airbnb/Booking.com: '
                                .route('ical.export', ['property' => $record, 'token' => $record->icalToken()]))
                            ->persistent()
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
