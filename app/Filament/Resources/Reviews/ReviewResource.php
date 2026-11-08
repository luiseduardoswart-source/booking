<?php

namespace App\Filament\Resources\Reviews;

use App\Support\Access;
use App\Filament\Concerns\WithAccess;
use App\Enums\ReviewStatus;
use App\Filament\Resources\Reviews\Pages\ManageReviews;
use App\Models\Review;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReviewResource extends Resource
{
    use WithAccess;

    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    public static function getModelLabel(): string
    {
        return __('review');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reviews');
    }

    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string
    {
        return __('Reviews');
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = Review::query()->where('status', ReviewStatus::Pending->value)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('property.name')
                    ->label(__('Accommodation'))
                    ->sortable(),
                TextColumn::make('guest_name')
                    ->label(__('Guest'))
                    ->searchable()
                    ->description(fn (Review $record) => $record->booking?->booking_number),
                TextColumn::make('rating')
                    ->label(__('Rating'))
                    ->formatStateUsing(fn (int $state) => str_repeat('★', $state).str_repeat('☆', 5 - $state))
                    ->color('warning')
                    ->sortable(),
                TextColumn::make('comment')
                    ->label(__('Review text'))
                    ->limit(60)
                    ->wrap()
                    ->tooltip(fn (Review $record) => $record->comment)
                    ->placeholder('—')
                    ->description(fn (Review $record) => $record->host_reply ? '↳ '.\Illuminate\Support\Str::limit($record->host_reply, 60) : null),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('created_at')
                    ->label(__('Received'))
                    ->dateTime('d-m-Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ReviewStatus::class),
                SelectFilter::make('property_id')
                    ->label(__('Accommodation'))
                    ->relationship('property', 'name', Access::onlyOwn(...)),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('View'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(__('Review'))
                    ->modalContent(fn (Review $record) => view('filament.reviews.view', ['review' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
                Action::make('answer')
                    ->label(__('Reply'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->schema([
                        \Filament\Forms\Components\Textarea::make('host_reply')
                            ->label(__('Host reply (visible on the site)'))
                            ->rows(4)
                            ->default(fn (Review $record) => $record->host_reply)
                            ->required(),
                    ])
                    ->action(function (Review $record, array $data) {
                        $record->update([
                            'host_reply' => $data['host_reply'],
                            'host_replied_at' => $record->host_replied_at ?? now(),
                        ]);

                        Notification::make()->title(__('Reply posted'))->success()->send();
                    }),
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (Review $record) => $record->status !== ReviewStatus::Approved)
                    ->action(function (Review $record) {
                        $record->update(['status' => ReviewStatus::Approved]);

                        Notification::make()->title(__('Review approved'))->success()->send();
                    }),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Review $record) => $record->status !== ReviewStatus::Rejected)
                    ->action(function (Review $record) {
                        $record->update(['status' => ReviewStatus::Rejected]);

                        Notification::make()->title(__('Review rejected'))->success()->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function canCreate(): bool
    {
        return false; // reviews komen alleen via gasten binnen
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReviews::route('/'),
        ];
    }
}
