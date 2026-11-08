<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\Properties\Schemas\PropertyForm;
use App\Models\Property;
use App\Support\Access;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * Edits the hidden master record: the default settings that apply to all properties without their own values. Reuses exactly
 * the same tabs as the property form — no duplicate code.
 */
class EditDefaults extends EditRecord
{
    protected static string $resource = PropertyResource::class;

    public function mount(int|string $record = null): void
    {
        parent::mount(Property::master()->getKey());
    }

    /**
     * A screen of its own in the permissions, separate from Properties: the
     * defaults apply to the whole platform and belong to no host.
     */
    public static function canAccess(array $parameters = []): bool
    {
        return Access::may(auth()->user(), 'defaults', Access::EDIT);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** Filament normally asks "may you see Properties" here; this page has its own permission. */
    public static function authorizeResourceAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function resolveRecord(int|string $key): Model
    {
        return Property::master();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Default accommodation settings');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('These defaults apply to all accommodations. Anything you fill in on an accommodation itself takes precedence.');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('defaults')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        PropertyForm::pricesTab(),
                        PropertyForm::calendarRulesTab(),
                        PropertyForm::paymentsTab(),
                        PropertyForm::mailsTab(),
                        PropertyForm::socialMediaTab(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return []; // no delete/view for the master record
    }

    protected function getRedirectUrl(): ?string
    {
        return null; // stay on the page after saving
    }
}
