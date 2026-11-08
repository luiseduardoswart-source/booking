<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Concerns\WithAccess;
use App\Engines\PaymentEngine;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Support\PaymentLink;
use App\Models\Payment;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

class PaymentResource extends Resource
{
    use WithAccess;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getModelLabel(): string
    {
        return __('payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payments');
    }

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return __('Payments');
    }

    public static function table(Table $table): Table
    {
        return $table
            // One query for what has been refunded per payment, instead of one per row.
            ->modifyQueryUsing(fn ($query) => $query->withSum('refunds', 'amount_cents'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('booking.booking_number')
                    ->label(__('Booking'))
                    ->searchable()
                    ->url(fn (Payment $record) => route('filament.admin.resources.bookings.edit', $record->booking)),
                TextColumn::make('booking.guest_name')
                    ->label(__('Guest')),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->formatStateUsing(fn (Payment $record) => $record->kind())
                    ->badge()
                    ->color('gray'),
                TextColumn::make('amount_cents')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (int $state, Payment $record) => Money::format($state, $record->booking?->currency)),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('provider')
                    ->label(__('Provider')),
                TextColumn::make('paid_at')
                    ->label(__('Paid on'))
                    ->dateTime('d-m-Y H:i')
                    ->placeholder(__('—')),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(PaymentTransactionStatus::class),
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(PaymentType::class),
            ])
            ->recordActions([
                Action::make('link_copy')
                    ->label(__('Payment link'))
                    ->icon(Heroicon::OutlinedLink)
                    ->visible(fn (Payment $record) => $record->hasOpenLink())
                    ->url(fn (Payment $record) => $record->booking->paymentLink($record->type))
                    ->openUrlInNewTab(),
                PaymentLink::copyAction('copy', fn (Payment $record) => $record->booking->paymentLink($record->type))
                    ->visible(fn (Payment $record) => $record->hasOpenLink()),
                PaymentLink::mailAction('mail')
                    ->visible(fn (Payment $record) => $record->hasOpenLink()),
                Action::make('status_refresh')
                    ->label(__('Refresh status'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize(fn (Payment $record): bool => static::canEdit($record))
                    ->visible(fn (Payment $record) => $record->status === PaymentTransactionStatus::Open && $record->provider_payment_id !== null)
                    ->action(function (Payment $record) {
                        app(PaymentEngine::class)->syncFromProvider($record);

                        Notification::make()
                            ->title(__('Status updated'))
                            ->success()
                            ->send();
                    }),
                Action::make('payment_receipt')
                    ->label(__('Payment receipt (PDF)'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn (Payment $record) => $record->status === PaymentTransactionStatus::Paid)
                    ->action(fn (Payment $record) => response()->streamDownload(
                        fn () => print(app(\App\Engines\DocumentEngine::class)->receipt($record)),
                        "payment_receipt-{$record->booking->booking_number}-{$record->id}.pdf",
                    )),
                Action::make('refund')
                    ->label(__('Refund'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Payment $record) => __('Refund :amount?', ['amount' => Money::format($record->remainingRefundableCents(), $record->booking?->currency)]))
                    ->authorize(fn (Payment $record): bool => static::canEdit($record))
                    ->visible(fn (Payment $record) => $record->remainingRefundableCents() > 0)
                    ->action(function (Payment $record) {
                        try {
                            app(PaymentEngine::class)->refund($record);

                            Notification::make()
                                ->title(__('Refund created'))
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()
                                ->title(__('Refund failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        } catch (\Throwable $e) {
                            // Payment provider error: details to the log, not to the screen.
                            report($e);

                            Notification::make()
                                ->title(__('Refund failed'))
                                ->body(__('The payment provider declined the refund. Check the provider\'s dashboard to see whether anything has already been refunded.'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function canCreate(): bool
    {
        return false; // payments are only created via the PaymentEngine
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePayments::route('/'),
        ];
    }
}
