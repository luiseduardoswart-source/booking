<?php

namespace App\Filament\Resources\Cleaners;

use App\Filament\Resources\Cleaners\Pages\ManageCleaners;
use App\Models\Cleaner;
use App\Support\CleaningSchedule;
use App\Support\Languages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Cleaners (owner only): who cleans which accommodations. They get the
 * changeovers by email and on a private page without an account; the owner
 * opens that page, sends the email now or gives a new link, which closes
 * the old one.
 */
class CleanerResource extends Resource
{
    protected static ?string $model = Cleaner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 8;

    public static function getModelLabel(): string
    {
        return __('cleaner');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaners');
    }

    public static function getNavigationLabel(): string
    {
        return __('Cleaning');
    }

    /** Owner only: the schedules show every accommodation's stays. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return (auth()->user()?->isAdministrator() ?? false) ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label(__('Email address'))
                ->email()
                ->required()
                ->maxLength(255),
            TextInput::make('phone')
                ->label(__('Phone'))
                ->tel()
                ->maxLength(50),
            Select::make('locale')
                ->label(__('Language of the emails'))
                ->options(fn (): array => collect(Languages::active())->mapWithKeys(fn (string $code): array => [$code => Languages::name($code)])->all())
                ->default(fn (): string => Languages::default())
                ->selectablePlaceholder(false)
                ->required()
                ->native(false),
            Select::make('frequency')
                ->label(__('Email with the changeovers'))
                ->options([
                    Cleaner::DAILY => __('Every morning, the coming 7 days'),
                    Cleaner::WEEKLY => __('Every Monday, the coming 14 days'),
                ])
                ->default(Cleaner::DAILY)
                ->selectablePlaceholder(false)
                ->required()
                ->native(false),
            Toggle::make('active')
                ->label(__('Active (gets the emails and can open the page)'))
                ->default(true)
                ->inline(false),
            Select::make('properties')
                ->label(__('Accommodations'))
                ->relationship('properties', 'name')
                ->multiple()
                ->preload()
                ->required()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('properties:id,name'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Cleaner $cleaner): string => $cleaner->email),
                TextColumn::make('properties.name')
                    ->label(__('Accommodations'))
                    ->badge(),
                TextColumn::make('frequency')
                    ->label(__('Email'))
                    ->formatStateUsing(fn (string $state): string => $state === Cleaner::WEEKLY ? __('Every Monday') : __('Every morning')),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('open_schedule')
                    ->label(__('Open the schedule'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->url(fn (Cleaner $cleaner): string => $cleaner->scheduleUrl(), shouldOpenInNewTab: true)
                    ->visible(fn (Cleaner $cleaner): bool => $cleaner->active),
                Action::make('send_now')
                    ->label(__('Send the email now'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn (Cleaner $cleaner): bool => $cleaner->active)
                    ->action(fn (Cleaner $cleaner) => CleaningSchedule::mail($cleaner)
                        ? Notification::make()->success()->title(__('Email sent to :name', ['name' => $cleaner->name]))->send()
                        : Notification::make()->warning()->title(__('No email sent: no changeovers in the coming days, or the email could not be sent'))->send()),
                Action::make('new_link')
                    ->label(__('Give a new link'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('The link the cleaner has now stops working. The next email has the new link.'))
                    ->action(function (Cleaner $cleaner): void {
                        $cleaner->newLink();
                        Notification::make()->success()->title(__('New link made'))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No cleaners yet'))
            ->emptyStateDescription(__('Add a cleaner to send them the changeovers of their accommodations.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCleaners::route('/'),
        ];
    }
}
