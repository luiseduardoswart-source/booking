<?php

namespace App\Filament\Resources\Forms;

use App\Filament\Concerns\WithAccess;
use App\Filament\Resources\Forms\Pages\CreateForm;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\Forms\Pages\ListForms;
use App\Filament\Resources\FormSubmissions\FormSubmissionResource;
use App\Filament\Support\TranslationFields;
use App\Models\Form;
use App\Models\Setting;
use App\Support\Languages;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The form builder: own forms (shown below a page) and the two fixed forms,
 * the booking form and the contact form. The fixed forms cannot be deleted
 * and keep their fixed fields; every form can get own fields.
 */
class FormResource extends Resource
{
    use WithAccess;

    protected static ?string $model = Form::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    // Next to Pages: forms are placed on pages.
    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('form');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forms');
    }

    public static function getNavigationLabel(): string
    {
        return __('Forms');
    }

    public static function form(Schema $schema): Schema
    {
        $isSystem = fn (?Form $record): bool => (bool) $record?->isSystem();
        $isBooking = fn (?Form $record): bool => $record?->system === Form::BOOKING;

        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Form'))
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label(__('Name in the admin'))
                            ->required()
                            ->maxLength(100)
                            ->disabled($isSystem)
                            ->formatStateUsing(fn (?string $state, ?Form $record) => $record?->isSystem() ? __((string) $state) : $state),
                        TextInput::make('recipients')
                            ->label(__('Send submissions to'))
                            ->placeholder(fn (): string => (string) Setting::get('company', 'email', config('mail.from.address')))
                            ->hint(__('Several addresses separated by commas. Empty = the company email address.'))
                            ->maxLength(1000)
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $address) {
                                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                                        $fail(__(':address is not a valid email address.', ['address' => $address]));
                                    }
                                }
                            })
                            ->hidden($isBooking),
                        Toggle::make('active')
                            ->label(__('Active'))
                            ->default(true)
                            ->hidden($isSystem),
                    ]),

                TranslationFields::languageSection(
                    __('Texts'),
                    __('Choose a language. Empty = the English text, or the standard text of the site.'),
                    [
                        'title' => fn (string $path) => TextInput::make($path)
                            ->label(__('Title above the form'))
                            ->maxLength(255)
                            ->hidden($isSystem),
                        'submit_label' => fn (string $path) => TextInput::make($path)
                            ->label(__('Text on the button'))
                            ->placeholder(__('Send'))
                            ->maxLength(60),
                        'success_message' => fn (string $path) => Textarea::make($path)
                            ->label(__('Message after sending'))
                            ->placeholder(__('Thank you for your message! We will get back to you as soon as possible.'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ],
                    ['title', 'submit_label', 'success_message'],
                    columns: 2,
                )->hidden($isBooking)->columnSpanFull(),

                Section::make(__('Fixed fields'))
                    ->description(__('These fields are always on this form and cannot be removed. Add your own fields below.'))
                    ->visible($isSystem)
                    ->components([
                        Text::make(fn (?Form $record): string => collect(Form::FIXED_FIELDS[$record?->system] ?? [])
                            ->map(fn (array $fixed) => __($fixed[0]))
                            ->implode(' · ')),
                        CheckboxList::make('hidden_fields')
                            ->label(__('Hide on the booking form'))
                            ->options(fn (): array => collect(Form::FIXED_FIELDS[Form::BOOKING])
                                ->filter(fn (array $fixed) => $fixed[1])
                                ->map(fn (array $fixed) => __($fixed[0]))
                                ->all())
                            ->columns(3)
                            ->visible($isBooking),
                    ]),

                Section::make(__('Own fields'))
                    ->description(fn (?Form $record): string => $record?->system === Form::BOOKING
                        ? __('Extra questions on the booking form. The answers are with the booking and can be inserted in the automatic mails with {{.')
                        : __('Drag to change the order. Label and options per language; empty = the English text.'))
                    ->components([self::fieldsBuilder()]),
            ]);
    }

    /** The list of own fields: type, label and options per language, required, width. */
    private static function fieldsBuilder(): Repeater
    {
        $type = fn (Get $get): ?string => $get('type');

        return Repeater::make('fields')
            ->label(__('Own fields'))
            ->hiddenLabel()
            ->schema([
                Hidden::make('key'),
                Select::make('type')
                    ->label(__('Type'))
                    ->options(fn (): array => array_map(fn (string $label) => __($label), Form::TYPES))
                    ->default('text')
                    ->required()
                    ->live()
                    ->native(false),
                Select::make('width')
                    ->label(__('Width'))
                    ->options(['full' => __('Full width'), 'half' => __('Half width')])
                    ->default('full')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->visible(fn (Get $get): bool => ! in_array($type($get), ['heading', 'checkbox'], true)),
                Toggle::make('required')
                    ->label(__('Required'))
                    ->inline(false)
                    ->visible(fn (Get $get): bool => $type($get) !== 'heading'),
                TranslationFields::languageTabs('field', fn (string $locale): array => [
                    TextInput::make("label.{$locale}")
                        ->label(fn (Get $get): string => $type($get) === 'heading' ? __('Subheading') : __('Label'))
                        ->required($locale === Languages::SOURCE)
                        ->maxLength(255),
                    TextInput::make("placeholder.{$locale}")
                        ->label(__('Example text in the field'))
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => in_array($type($get), Form::PLACEHOLDER_TYPES, true)),
                    Textarea::make("options.{$locale}")
                        ->label(__('Options, one per line'))
                        ->hint($locale === Languages::SOURCE ? null : __('Same order as in English'))
                        ->rows(4)
                        ->required(fn (Get $get): bool => $locale === Languages::SOURCE && in_array($type($get), Form::CHOICE_TYPES, true))
                        ->visible(fn (Get $get): bool => in_array($type($get), Form::CHOICE_TYPES, true)),
                ]),
            ])
            ->columns(3)
            ->reorderable()
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => Form::text($state, 'label', Languages::SOURCE) ?: null)
            ->addActionLabel(__('Add field'))
            ->defaultItems(0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->formatStateUsing(fn (Form $record) => $record->isSystem() ? __($record->name) : $record->name)
                    ->description(fn (Form $record) => $record->isSystem() ? __('Fixed form') : null),
                TextColumn::make('own_fields')
                    ->label(__('Own fields'))
                    ->state(fn (Form $record): int => count($record->inputFields())),
                TextColumn::make('submissions_count')
                    ->label(__('Submissions'))
                    ->counts('submissions')
                    ->formatStateUsing(fn ($state, Form $record) => $record->system === Form::BOOKING ? '—' : $state),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('submissions')
                    ->label(__('Submissions'))
                    ->icon(Heroicon::OutlinedInboxStack)
                    ->color('gray')
                    ->visible(fn (Form $record): bool => $record->system !== Form::BOOKING && FormSubmissionResource::canViewAny())
                    ->url(fn (Form $record): string => FormSubmissionResource::getUrl('index', ['filters' => ['form' => ['value' => $record->id]]])),
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Form $record): bool => $record->isSystem()),
            ])
            ->checkIfRecordIsSelectableUsing(fn (Form $record): bool => ! $record->isSystem())
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForms::route('/'),
            'create' => CreateForm::route('/create'),
            'edit' => EditForm::route('/{record}/edit'),
        ];
    }
}
