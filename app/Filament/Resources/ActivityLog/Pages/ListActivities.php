<?php

namespace App\Filament\Resources\ActivityLog\Pages;

use App\Filament\Resources\ActivityLog\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    /** The same name as in the menu. */
    public function getTitle(): string
    {
        return __('Log');
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
