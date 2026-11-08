<?php

namespace App\Filament\Resources\Forms\Pages;

use App\Filament\Resources\Forms\FormResource;
use App\Models\Form;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditForm extends EditRecord
{
    protected static string $resource = FormResource::class;

    /**
     * A new field gets its fixed key when saving; load it into the screen, or
     * renaming the field and saving again would give it another key.
     */
    protected function afterSave(): void
    {
        $this->fillForm();
    }

    protected function getHeaderActions(): array
    {
        return [
            // The booking and contact forms cannot be deleted.
            DeleteAction::make()->hidden(fn (Form $record): bool => $record->isSystem()),
        ];
    }
}
