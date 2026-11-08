<?php

namespace App\Filament\Resources\Amenities\Pages;

use App\Filament\Resources\Amenities\AmenityResource;
use App\Models\Amenity;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageAmenities extends ManageRecords
{
    protected static string $resource = AmenityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->multipleAddAction(),
            CreateAction::make(),
        ];
    }

    /** Een hele lijst in één keer: één naam per regel. */
    private function multipleAddAction(): Action
    {
        return Action::make('multipleAdd')
            ->label(__('Add several'))
            ->icon(Heroicon::OutlinedQueueList)
            ->color('gray')
            ->authorize(fn (): bool => AmenityResource::canCreate())
            ->modalSubmitActionLabel(__('Add'))
            ->schema([
                Textarea::make('names')
                    ->label(__('Amenities'))
                    ->helperText(__('One English name per line, or paste a whole list. Names that already exist are skipped. The icon is recognised by name and can be changed per amenity afterwards.'))
                    ->rows(12)
                    ->required()
                    ->maxLength(20000)
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                        foreach (Amenity::namesFromList((string) $value) as $name) {
                            if (mb_strlen($name) > Amenity::MAX_NAME) {
                                $fail(__('A name may be at most :max characters long.', ['max' => Amenity::MAX_NAME]));

                                return;
                            }
                        }
                    }),
            ])
            ->action(function (array $data) {
                ['added' => $added, 'skipped' => $skipped] = Amenity::addFromList($data['names']);

                Notification::make()
                    ->title(trans_choice('{0} No new amenities added.|{1} 1 amenity added.|[2,*] :count amenities added.', $added))
                    ->body($skipped > 0
                        ? trans_choice('{1} 1 name already existed and was skipped.|[2,*] :count names already existed and were skipped.', $skipped)
                        : null)
                    ->success()
                    ->send();
            });
    }
}
