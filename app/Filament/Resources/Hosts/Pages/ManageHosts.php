<?php

namespace App\Filament\Resources\Hosts\Pages;

use App\Filament\Resources\Hosts\HostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHosts extends ManageRecords
{
    protected static string $resource = HostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
