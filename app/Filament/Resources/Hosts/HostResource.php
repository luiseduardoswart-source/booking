<?php

namespace App\Filament\Resources\Hosts;

use App\Filament\Concerns\WithAccess;
use App\Filament\Support\TranslationFields;
use App\Filament\Resources\Hosts\Pages\ManageHosts;
use App\Models\Host;
use App\Models\Property;
use App\Support\Access;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HostResource extends Resource
{
    use WithAccess;

    protected static ?string $model = Host::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    public static function getModelLabel(): string
    {
        return __('host');
    }

    public static function getPluralModelLabel(): string
    {
        return __('hosts');
    }

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Hosts');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...static::profileFields(),
            Select::make('properties')
                ->label(__('Accommodations'))
                // Hosts is a platform screen: a host may not assign anything to themselves.
                ->hidden(fn (): bool => Access::isHost(auth()->user()))
                ->relationship('properties', 'name')
                ->multiple()
                ->preload()
                ->columnSpanFull(),
        ]);
    }

    /**
     * Name, photo and text of a host. Also used on the profile page,
     * where a host edits their own profile (App\Filament\Pages\Profile).
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function profileFields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Name'))
                ->required(),
            SpatieMediaLibraryFileUpload::make('avatar')
                ->label(__('Profile photo'))
                ->collection('avatar')
                ->image()
                // No SVG: a host could put a script in it.
                ->acceptedFileTypes(Property::PHOTO_MIMES)
                ->avatar(),
            TranslationFields::languageSection(
                __('About the host (visible on the site)'),
                __('The "about the host" text as visitors see it in their own language. Empty = the English text. The name stays the same in every language.'),
                ['bio' => fn (string $path) => Textarea::make($path)->hiddenLabel()->rows(4)],
                ['bio'],
            )->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('avatar')
                    ->label(__('Photo'))
                    ->collection('avatar')
                    ->conversion('thumb')
                    ->circular(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),
                TextColumn::make('properties_count')
                    ->label(__('Accommodations'))
                    ->counts('properties'),
            ])
            ->headerActions([
                TranslationFields::fillEmptyLanguagesAction(
                    __('Fills the missing languages of the "about the host" text for each host from English. Existing translations are left untouched.'),
                    fn () => Host::all(),
                    ['bio'],
                    '{0} Nothing to translate: every host already has a text in all languages.|{1} 1 translation added — please review it.|[2,*] :count translations added — please review them.',
                ),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHosts::route('/'),
        ];
    }
}
