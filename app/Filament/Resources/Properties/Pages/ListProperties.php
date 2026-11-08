<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Resources\Properties\PropertyResource;
use App\Services\Importing\ListingImportException;
use App\Services\Importing\ListingImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProperties extends ListRecords
{
    protected static string $resource = PropertyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label(__('Import from link'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->modalHeading(__('Import listing'))
                ->modalDescription(__('Paste the link to your Airbnb, Vrbo or Booking listing. We fetch what the page makes public; you complete the rest afterwards. The property starts as a draft (not visible).'))
                ->schema([
                    TextInput::make('url')
                        ->label(__('Link to the listing'))
                        ->url()
                        ->required()
                        ->placeholder('https://...'),
                ])
                ->action(function (array $data) {
                    try {
                        $property = app(ListingImportService::class)->importFromUrl($data['url']);
                    } catch (ListingImportException $e) {
                        Notification::make()->title(__('Import failed'))->body($e->getMessage())->danger()->send();

                        return;
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title(__('Import failed'))->body(__('Something went wrong while fetching. Try again later or enter the property manually.'))->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Property imported — review and complete it.'))->success()->send();

                    $this->redirect(PropertyResource::getUrl('edit', ['record' => $property]));
                }),
            CreateAction::make(),
        ];
    }
}
