<?php

namespace App\Filament\Resources\ChannelConnections;

use App\Filament\Support\Switches;
use App\Support\Access;
use App\Filament\Concerns\WithAccess;
use App\Filament\Resources\ChannelConnections\Pages\ManageChannelConnections;
use App\Models\ChannelConnection;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

class ChannelConnectionResource extends Resource
{
    use WithAccess;

    protected static ?string $model = ChannelConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    public static function getModelLabel(): string
    {
        return __('channel connection');
    }

    public static function getPluralModelLabel(): string
    {
        return __('channel connections');
    }

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('Channel connections');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('property_id')
                    ->label(__('Accommodation'))
                    ->relationship('property', 'name', Access::onlyOwn(...))
                    ->required(),
                TextInput::make('name')
                    ->label(__('Name (e.g. Airbnb)'))
                    ->required(),
                TextInput::make('url')
                    ->label(__('iCal URL of the platform'))
                    ->url()
                    ->required()
                    // The server fetches this address: not one inside its own network.
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                        try {
                            \App\Support\OutboundUrl::check((string) $value);
                        } catch (\InvalidArgumentException $e) {
                            $fail($e->getMessage());
                        }
                    })
                    ->columnSpanFull(),
                Toggle::make('active')
                    ->label(__('Active'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('property.name')
                    ->label(__('Accommodation'))
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('Channel'))
                    ->searchable(),
                Switches::column('active', __('Active'), static::class),
                TextColumn::make('last_synced_at')
                    ->label(__('Last sync'))
                    ->dateTime('d-m-Y H:i')
                    ->placeholder(__('not yet')),
                TextColumn::make('last_error')
                    ->label(__('Last error'))
                    ->limit(40)
                    ->placeholder(__('—'))
                    ->color('danger'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Action::make('now_synchronize')
                    ->label(__('Synchronise now'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    // Syncs all connections of the platform, so not for a host.
                    ->authorize(fn (): bool => ! Access::isHost(auth()->user()) && static::can('update'))
                    ->action(function () {
                        Artisan::call('channels:sync');

                        Notification::make()
                            ->title(__('Synchronisation completed'))
                            ->body(trim(Artisan::output()) ?: 'Geen actieve koppelingen.')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageChannelConnections::route('/'),
        ];
    }
}
