<?php

namespace App\Filament\Resources\Cleaners\Pages;

use App\Filament\Resources\Cleaners\CleanerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCleaners extends ManageRecords
{
    protected static string $resource = CleanerResource::class;

    /** The same name as in the menu. */
    public function getTitle(): string
    {
        return __('Cleaning');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
