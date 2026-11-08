<?php

namespace App\Filament\Resources\Amenities;

use App\Filament\Concerns\WithAccess;
use App\Filament\Forms\Components\IconPicker;
use App\Filament\Support\TranslationFields;
use App\Filament\Resources\Amenities\Pages\ManageAmenities;
use App\Models\Amenity;
use App\Support\AmenityIcons;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;

class AmenityResource extends Resource
{
    use WithAccess;

    protected static ?string $model = Amenity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public static function getModelLabel(): string
    {
        return __('amenity');
    }

    public static function getPluralModelLabel(): string
    {
        return __('amenities');
    }

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Amenities');
    }

    protected static ?string $recordTitleAttribute = 'name';

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
                            ->when($locale === (new Amenity)->sourceLanguage(), fn (TextInput $field) => $field->required()->unique(ignoreRecord: true))
                            // Shows the current display (built-in translation or the
                            // English name), so it is visible that it already works
                            // without having to fill in anything.
                            ->placeholder(fn (?Amenity $record) => $record && $locale !== (new Amenity)->sourceLanguage()
                                ? Lang::get($record->name, [], $locale)
                                : null),
                    ],
                    ['name'],
                    source: (new Amenity)->sourceLanguage(),
                )->columnSpanFull(),

                IconPicker::make('icon')
                    ->hint(__('Empty = detected automatically, otherwise a check mark')),
                Select::make('category')
                    ->label(__('Category'))
                    ->options(fn () => Amenity::categoryOptions())
                    ->default(Amenity::OTHER)
                    ->required()
                    ->hint(__('Where it appears in the picker of an accommodation')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->formatStateUsing(fn (Amenity $record) => $record->localizedName())
                    ->description(fn (Amenity $record) => $record->localizedName() === $record->name ? null : $record->name),
                TextColumn::make('icon')
                    ->label(__('Icon'))
                    // The icon the site shows, even if it was chosen automatically.
                    ->state(fn (Amenity $record) => $record->iconName())
                    // HtmlString: ->html() would filter the SVG out. The icon comes from
                    // our own file and the name is escaped in option().
                    ->formatStateUsing(fn (string $state) => new HtmlString(
                        AmenityIcons::option($state, once(fn () => Amenity::iconOptions())[$state] ?? $state),
                    ))
                    ->description(fn (Amenity $record) => $record->icon ? null : __('automatic')),
                TextColumn::make('category')
                    ->label(__('Category'))
                    ->formatStateUsing(fn (Amenity $record) => Amenity::categoryOptions()[$record->resolvedCategory()])
                    ->default(Amenity::OTHER)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->options(fn () => Amenity::categoryOptions())
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        // Without a category an amenity falls under Other.
                        Amenity::OTHER => $query->where(fn ($sub) => $sub->whereNull('category')->orWhere('category', Amenity::OTHER)),
                        default => $query->where('category', $data['value']),
                    }),
            ])
            ->headerActions([
                TranslationFields::fillEmptyLanguagesAction(
                    __('Fills the missing languages for each amenity from the English name. Standard amenities are skipped — they already have a built-in translation. Existing translations are left untouched.'),
                    fn () => Amenity::all(),
                    ['name'],
                    '{0} Nothing to translate: every amenity already has a name in all languages.|{1} 1 translation added — please review it.|[2,*] :count translations added — please review them.',
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
            'index' => ManageAmenities::route('/'),
        ];
    }
}
