<?php

namespace App\Filament\Resources\Coupons;

use App\Filament\Support\Switches;
use App\Support\Access;
use App\Filament\Concerns\WithAccess;
use App\Filament\Resources\Coupons\Pages\ManageCoupons;
use App\Models\Coupon;
use App\Models\Property;
use App\Support\Money;
use App\Support\Currencies;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CouponResource extends Resource
{
    use WithAccess;

    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    // Same sort as Payments: comes right after it (alphabetical), before Reviews.
    protected static ?int $navigationSort = 7;

    public static function getModelLabel(): string
    {
        return __('coupon code');
    }

    public static function getPluralModelLabel(): string
    {
        return __('coupon codes');
    }

    public static function getNavigationLabel(): string
    {
        return __('Coupon codes');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label(__('Code'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->extraInputAttributes(['style' => 'text-transform: uppercase;'])
                    ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                    ->suffixAction(
                        Action::make('generate')
                            ->label(__('Generate'))
                            ->icon(Heroicon::OutlinedSparkles)
                            ->action(fn (callable $set) => $set('code', strtoupper(Str::random(8)))),
                    ),
                TextInput::make('description')
                    ->label(__('Description (internal)')),
                Select::make('type')
                    ->label(__('Discount type'))
                    ->options([
                        Coupon::TYPE_PERCENT => __('Percentage of the stay'),
                        Coupon::TYPE_FIXED => __('Fixed amount'),
                    ])
                    ->default(Coupon::TYPE_PERCENT)
                    ->required()
                    ->live()
                    ->native(false),
                TextInput::make('value')
                    ->label(fn (callable $get) => $get('type') === Coupon::TYPE_FIXED ? __('Amount') : __('Percentage'))
                    ->numeric()
                    ->required()
                    ->minValue(fn (callable $get) => $get('type') === Coupon::TYPE_FIXED ? 0.01 : 1)
                    ->maxValue(fn (callable $get) => $get('type') === Coupon::TYPE_FIXED ? null : 100)
                    // A fixed amount is in the currency of the chosen accommodation,
                    // or the default currency for "all accommodations".
                    ->prefix(fn (callable $get) => $get('type') === Coupon::TYPE_FIXED
                        ? Currencies::character(Property::find($get('property_id'))?->priceCurrency() ?? Money::defaultCurrency())
                        : null)
                    ->suffix(fn (callable $get) => $get('type') === Coupon::TYPE_FIXED ? null : '%')
                    // Fixed amount: euros in the form, cents in the database.
                    ->formatStateUsing(fn ($state, callable $get) => $state === null ? null : ($get('type') === Coupon::TYPE_FIXED ? $state / 100 : $state))
                    ->dehydrateStateUsing(fn ($state, callable $get) => $state === null || $state === '' ? null : ($get('type') === Coupon::TYPE_FIXED ? (int) round((float) $state * 100) : (int) $state)),
                Select::make('property_id')
                    ->label(__('Only valid for'))
                    ->live()
                    ->relationship('property', 'name', Access::onlyOwn(...))
                    ->placeholder(__('All accommodations'))
                    // "All accommodations" also affects other people's: not for a host.
                    ->required(fn (): bool => Access::isHost(auth()->user()))
                    ->preload(),
                TextInput::make('max_uses')
                    ->label(__('Max. number of uses'))
                    ->numeric()
                    ->minValue(1)
                    ->hint(__('Empty = unlimited')),
                DatePicker::make('valid_from')
                    ->label(__('Valid from')),
                DatePicker::make('valid_until')
                    ->label(__('Valid until'))
                    ->afterOrEqual('valid_from'),
                Toggle::make('active')
                    ->label(__('Active'))
                    ->default(true)
                    ->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->searchable()
                    ->weight('bold')
                    ->copyable()
                    ->description(fn (Coupon $record) => $record->description),
                TextColumn::make('value')
                    ->label(__('Discount'))
                    ->formatStateUsing(fn (Coupon $record) => $record->discountLabel()),
                TextColumn::make('property.name')
                    ->label(__('Accommodation'))
                    ->placeholder(__('All')),
                TextColumn::make('used_count')
                    ->label(__('Used'))
                    ->formatStateUsing(fn (Coupon $record) => $record->max_uses !== null
                        ? "{$record->used_count} / {$record->max_uses}"
                        : (string) $record->used_count),
                TextColumn::make('valid_until')
                    ->label(__('Valid until'))
                    ->date('d-m-Y')
                    ->placeholder('—')
                    ->sortable(),
                Switches::column('active', __('Active'), static::class),
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
            'index' => ManageCoupons::route('/'),
        ];
    }
}
