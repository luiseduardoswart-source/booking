<?php

namespace App\Filament\Resources\PropertyTypes;

use App\Filament\Concerns\WithAccess;
use App\Filament\Support\TranslationFields;
use App\Filament\Resources\PropertyTypes\Pages\ManagePropertyTypes;
use App\Models\PropertyType;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Lang;

class PropertyTypeResource extends Resource
{
    use WithAccess;

    protected static ?string $model = PropertyType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('accommodation type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accommodation types');
    }

    public static function getNavigationLabel(): string
    {
        return __('Accommodation types');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([

                TranslationFields::languageSection(
                    __('Name'),
                    __('The name as visitors see it in their own language. The grey text is what is shown right now — only fill in what you want to override.'),
                    [
                        'name' => fn (string $path, string $locale) => TextInput::make($path)
                            ->hiddenLabel()
                            ->when($locale === (new PropertyType)->sourceLanguage(), fn (TextInput $field) => $field->required()->unique(ignoreRecord: true))
                            // Shows the current display (built-in translation or the
                            // English name), so it is visible that it already works
                            // without having to fill anything in.
                            ->placeholder(fn (?PropertyType $record) => $record && $locale !== (new PropertyType)->sourceLanguage()
                                ? Lang::get($record->name, [], $locale)
                                : null),
                    ],
                    ['name'],
                    source: (new PropertyType)->sourceLanguage(),
                )->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->formatStateUsing(fn (PropertyType $record) => $record->localizedName())
                    ->description(fn (PropertyType $record) => $record->localizedName() === $record->name ? null : $record->name),
                TextColumn::make('properties_count')
                    ->label(__('Accommodations'))
                    ->counts('properties'),
            ])
            ->headerActions([
                TranslationFields::fillEmptyLanguagesAction(
                    __('Fills the missing languages for each type from the English name. Standard types with a built-in translation are skipped. Existing translations are left untouched.'),
                    fn () => PropertyType::all(),
                    ['name'],
                    '{0} Nothing to translate: every type already has a name in all languages.|{1} 1 translation added — please review it.|[2,*] :count translations added — please review them.',
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
            'index' => ManagePropertyTypes::route('/'),
        ];
    }
}
