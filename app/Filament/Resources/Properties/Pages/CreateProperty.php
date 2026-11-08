<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\Properties\Schemas\PropertyForm;
use App\Models\Property;
use App\Services\Importing\ListingImportService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Illuminate\Support\Str;

/**
 * New accommodation = quick setup wizard (a few minutes, draggable
 * photos). The full tabbed form is on the edit screen for the details.
 */
class CreateProperty extends CreateRecord
{
    use HasWizard;

    protected static string $resource = PropertyResource::class;

    /** @var array<int, string> photo URLs from the "fill in from link" import */
    private array $importPhotoUrls = [];

    protected function getSteps(): array
    {
        return PropertyForm::wizardSteps();
    }

    /**
     * Generate a unique slug from the name automatically and keep the imported
     * photos aside (we download them after the property is created).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['name'] ?? __('accommodation'));
        }

        $this->importPhotoUrls = array_values(array_filter((array) ($data['import_photo_urls'] ?? []), 'is_string'));
        unset($data['import_photo_urls'], $data['import_url']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->importPhotoUrls !== []) {
            app(ListingImportService::class)->downloadPhotos($this->record, $this->importPhotoUrls);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'accommodation';
        $slug = $base;
        $n = 1;

        while (Property::withoutGlobalScope('withoutMaster')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
