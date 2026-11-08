<?php

namespace App\Filament\Resources\ChannelConnections\Pages;

use App\Filament\Resources\ChannelConnections\ChannelConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageChannelConnections extends ManageRecords
{
    protected static string $resource = ChannelConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
