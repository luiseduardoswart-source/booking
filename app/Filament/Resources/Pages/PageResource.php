<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Concerns\WithAccess;
use App\Filament\Support\Switches;
use App\Filament\Support\TranslationFields;
use App\Filament\Resources\Pages\Pages\ManagePages;
use App\Models\Page;
use App\Support\Languages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PageResource extends Resource
{
    use WithAccess;

    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    // Same sort as Properties: comes after it alphabetically, before Defaults.
    protected static ?int $navigationSort = 3;

    /**
     * Slugs already used by fixed routes (see routes/web.php); a page with
     * such a slug would never be reachable.
     */
    private const RESERVED_SLUGS = [
        'admin', 'api', 'accommodations', 'accommodation', 'payment', 'booking',
        'build', 'contact', 'ical', 'install', 'livewire', 'storage', 'language',
        'track', 'up', 'currency', 'vendor', 'webhooks',
    ];

    public static function getModelLabel(): string
    {
        return __('page');
    }

    public static function getPluralModelLabel(): string
    {
        return __("pages");
    }

    public static function getNavigationLabel(): string
    {
        return __("Pages");
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TranslationFields::languageSection(
                    __('Title and content'),
                    __('Choose a language: all its texts are together there. Empty = the English text. The slug is created automatically and opens the page in that language.'),
                    [
                        'title' => fn (string $path, string $locale) => TextInput::make($path)
                            ->label(__('Title'))
                            ->required($locale === Languages::SOURCE)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, callable $get, callable $set, ?Page $record) use ($locale) {
                                if ($locale !== Languages::SOURCE) {
                                    TranslationFields::fillEmptySlug($locale, $state, $get, $set);
                                } elseif ($record === null) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        'slug' => fn (string $path, string $locale) => TranslationFields::slugField($path, $locale)
                            ->helperText(__('The page will live on your domain as /slug.')),
                        'content' => fn (string $path) => RichEditor::make($path)
                            ->label(__('Content'))
                            ->columnSpanFull(),
                    ],
                    ['title', 'content'],
                    columns: 2,
                )->columnSpanFull(),

                Section::make(__("Photos"))
                    ->description(__('Large photo at the top of the page; multiple photos become a slider.'))
                    ->collapsed()
                    ->components([
                        ViewField::make('hero_images')
                            ->label('')
                            ->view('filament.forms.hero-images')
                            ->default([])
                            ->dehydrateStateUsing(fn ($state) => Page::cleanHeroes(is_array($state) ? $state : [])),
                    ]),

                TranslationFields::languageSection(
                    __('SEO'),
                    __('Choose a language. Empty = the English text.'),
                    [
                        'meta_title' => fn (string $path) => TextInput::make($path)->label(__('Meta title'))->maxLength(70),
                        'meta_description' => fn (string $path) => TextInput::make($path)->label(__('Meta description'))->maxLength(160),
                    ],
                    ['meta_title', 'meta_description'],
                    columns: 2,
                )->collapsed()->columnSpanFull(),

                Section::make(__('Form'))
                    ->description(__('A form from Forms, shown below the content of this page.'))
                    ->collapsed(fn (?Page $record): bool => $record?->form_id === null)
                    ->components([
                        Select::make('form_id')
                            ->label(__('Form on this page'))
                            ->relationship('form', 'name', fn ($query) => $query->whereNull('system'))
                            ->placeholder(__('No form'))
                            ->native(false),
                    ]),

                Section::make(__('Visibility'))
                    ->columns(4)
                    ->components([
                        Toggle::make('published')
                            ->label(__('Published'))
                            ->default(true)
                            ->inline(false),
                        Toggle::make('in_menu')
                            ->label(__('Link in the menu'))
                            ->default(true)
                            ->inline(false),
                        Toggle::make('in_footer')
                            ->label(__('Link in the footer'))
                            ->inline(false),
                        TextInput::make('sort')
                            ->label(__('Order'))
                            ->numeric()
                            ->default(0),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->searchable()
                    ->formatStateUsing(fn (Page $record) => $record->localized('title'))
                    ->description(fn (Page $record) => '/'.$record->slug),
                Switches::column('published', __('Published'), static::class),
                Switches::column('in_menu', __('Menu'), static::class),
                Switches::column('in_footer', __('Footer'), static::class),
                TextColumn::make('sort')
                    ->label(__('Order'))
                    ->sortable(),
            ])
            ->headerActions([
                TranslationFields::fillEmptyLanguagesAction(
                    __('Translates each page\'s still-empty titles and meta texts from English. The content (formatted text) you translate yourself per language — machine translation would damage the formatting.'),
                    fn () => Page::all(),
                    ['title', 'meta_title', 'meta_description'],
                    '{0} Nothing to translate: all titles and meta texts are already filled in.|{1} 1 translation added — please review it.|[2,*] :count translations added — please review them.',
                ),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('View'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Page $record) => url('/'.$record->slug), shouldOpenInNewTab: true),
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
            'index' => ManagePages::route('/'),
        ];
    }
}
