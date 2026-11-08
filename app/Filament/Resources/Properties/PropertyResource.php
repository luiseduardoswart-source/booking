<?php

namespace App\Filament\Resources\Properties;

use App\Filament\Concerns\WithAccess;
use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\Pages\ListProperties;
use App\Filament\Resources\Properties\Schemas\PropertyForm;
use App\Filament\Resources\Properties\Tables\PropertiesTable;
use App\Models\Property;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

use function Filament\Support\original_request;

class PropertyResource extends Resource
{
    use WithAccess;

    protected static ?string $model = Property::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    public static function getModelLabel(): string
    {
        return __('accommodation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accommodations');
    }

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('Accommodations');
    }

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * The Defaults page belongs to this resource as a separate route (to
     * reuse the form), but is not an accommodation — so it
     * does not count towards the "Accommodations" navigation state, and gets
     * its own navigation item below.
     */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [
            static::getRouteBaseName().'.index',
            static::getRouteBaseName().'.create',
            static::getRouteBaseName().'.edit',
        ];
    }

    public static function getNavigationItems(): array
    {
        return [...parent::getNavigationItems(), static::defaultsNavigation()];
    }

    /**
     * Accommodations and Defaults are separate permissions. Filament only shows the menu
     * of a resource if the user may see the list; whoever may only
     * change the defaults therefore only gets that item here.
     */
    public static function registerNavigationItems(): void
    {
        if (! static::canAccess() && EditDefaults::canAccess()) {
            Filament::getCurrentOrDefaultPanel()->navigationItems([static::defaultsNavigation()]);

            return;
        }

        parent::registerNavigationItems();
    }

    private static function defaultsNavigation(): NavigationItem
    {
        $defaultsRoute = static::getRouteBaseName().'.defaults';

        return NavigationItem::make(__('Default accommodation settings'))
            ->visible(fn (): bool => EditDefaults::canAccess())
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->sort(4)
            ->url(static::getUrl('defaults'))
            ->isActiveWhen(fn (): bool => original_request()->routeIs($defaultsRoute));
    }

    public static function form(Schema $schema): Schema
    {
        return PropertyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PropertiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProperties::route('/'),
            'create' => CreateProperty::route('/create'),
            'defaults' => \App\Filament\Resources\Properties\Pages\EditDefaults::route('/defaults'),
            'edit' => EditProperty::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
