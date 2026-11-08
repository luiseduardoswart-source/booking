<?php

namespace App\Filament\Resources\CalendarBlocks;

use App\Support\Access;
use App\Filament\Concerns\WithAccess;
use App\Enums\CalendarBlockType;
use App\Filament\Resources\CalendarBlocks\Pages\ManageCalendarBlocks;
use App\Models\CalendarBlock;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CalendarBlockResource extends Resource
{
    use WithAccess;

    protected static ?string $model = CalendarBlock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    // Blocks are now managed in the "Nightly prices" calendar; no separate menu item.
    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('block');
    }

    public static function getPluralModelLabel(): string
    {
        return __('blocks');
    }

    public static function getNavigationLabel(): string
    {
        return __('Calendar');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('property_id')
                    ->label(__('Accommodation'))
                    ->relationship('property', 'name', Access::onlyOwn(...))
                    ->required(),
                Select::make('type')
                    ->label(__('Type'))
                    ->options(CalendarBlockType::class)
                    ->default(CalendarBlockType::Blocked)
                    ->required(),
                DatePicker::make('start_date')
                    ->label(__('First blocked night'))
                    ->required(),
                DatePicker::make('end_date')
                    ->label(__('Last blocked night'))
                    ->required()
                    ->afterOrEqual('start_date'),
                Textarea::make('note')
                    ->label(__('Note'))
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date')
            ->columns([
                TextColumn::make('property.name')
                    ->label(__('Accommodation'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge(),
                TextColumn::make('start_date')
                    ->label(__('From (night)'))
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label(__('Until (night)'))
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->placeholder(__('manual')),
                TextColumn::make('note')
                    ->label(__('Note'))
                    ->limit(40),
            ])
            ->filters([
                SelectFilter::make('property_id')
                    ->label(__('Accommodation'))
                    ->relationship('property', 'name', Access::onlyOwn(...)),
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(CalendarBlockType::class),
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
            'index' => ManageCalendarBlocks::route('/'),
        ];
    }
}
