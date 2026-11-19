<?php

namespace App\Filament\Resources\Bookings;

use App\Support\Access;
use App\Filament\Concerns\WithAccess;
use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use Filament\Notifications\Notification;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Support\PaymentLink;
use App\Models\Booking;
use App\Models\Form;
use App\Support\FormAnswers;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BookingResource extends Resource
{
    use WithAccess;

    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getModelLabel(): string
    {
        return __('booking');
    }

    public static function getPluralModelLabel(): string
    {
        return __('bookings');
    }

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('Bookings');
    }

    protected static ?string $recordTitleAttribute = 'booking_number';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Stay'))
                    ->columns(2)
                    ->components([
                        Select::make('property_id')
                            ->label(__('Accommodation'))
                            ->relationship('property', 'name', Access::onlyOwn(...))
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('guests')
                            ->label(__('Number of guests'))
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->disabledOn('edit'),
                        DatePicker::make('arrival_date')
                            ->label(__('Check-in'))
                            ->required()
                            ->minDate(fn (string $operation) => $operation === 'create' ? today() : null)
                            ->disabledOn('edit'),
                        DatePicker::make('departure_date')
                            ->label(__('Check-out'))
                            ->required()
                            ->after('arrival_date')
                            ->disabledOn('edit'),
                    ]),

                Section::make(__('Guest details'))
                    ->columns(2)
                    ->components([
                        TextInput::make('guest_name')
                            ->label(__('Name'))
                            ->required(),
                        TextInput::make('guest_email')
                            ->label(__('Email address'))
                            ->email()
                            ->required(),
                        TextInput::make('guest_phone')
                            ->label(__('Phone')),
                        TextInput::make('guest_address')
                            ->label(__('Address')),
                        TextInput::make('guest_postal_code')
                            ->label(__('Postal code')),
                        TextInput::make('guest_city')
                            ->label(__('City')),
                        Select::make('guest_country')
                            ->label(__('Country'))
                            ->options(fn () => \App\Support\Countries::options())
                            ->searchable()
                            ->default('NL'),
                    ]),

                PaymentLink::section(),

                // The guest's answers to the own fields of the booking form (Forms → Booking form).
                Section::make(__('Answers on the booking form'))
                    ->columns(2)
                    ->visible(fn (?Booking $record): bool => filled($record?->form_answers))
                    ->components(fn (?Booking $record): array => self::answers($record)),

                Section::make(__('Remarks'))
                    ->columns(2)
                    ->components([
                        Textarea::make('notes')
                            ->label(__('Guest remarks'))
                            ->rows(3),
                        Textarea::make('internal_notes')
                            ->label(__('Internal notes (not visible to the guest)'))
                            ->rows(3),
                    ]),
            ]);
    }

    /** @return array<int, Text> one line per answer: label, then the answer */
    private static function answers(?Booking $record): array
    {
        $form = Form::fixed(Form::BOOKING);

        return collect((array) $record?->form_answers)
            ->reject(fn (array $answer) => ($answer['type'] ?? null) === 'heading')
            ->map(fn (array $answer) => Text::make(new HtmlString(
                '<strong>'.e(FormAnswers::label($answer, $form)).'</strong><br>'.nl2br(e(FormAnswers::value($answer, $form) ?: '—'))
            )))
            ->values()
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('booking_number')
                    ->label(__('Number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('property.name')
                    ->label(__('Accommodation'))
                    ->wrap()
                    ->sortable(),
                TextColumn::make('guest_name')
                    ->label(__('Guest'))
                    ->searchable(),
                TextColumn::make('arrival_date')
                    ->label(__('Check-in'))
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('departure_date')
                    ->label(__('Check-out'))
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('guests')
                    ->label(__('Guests'))
                    ->numeric(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('payment_status')
                    ->label(__('Payment'))
                    ->badge(),
                TextColumn::make('identity_status')
                    ->label(__('ID'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('total_cents')
                    ->label(__('Total'))
                    ->formatStateUsing(fn (int $state, Booking $record) => Money::format($state, $record->currency))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(BookingStatus::class),
                SelectFilter::make('payment_status')
                    ->label(__('Payment'))
                    ->options(PaymentStatus::class),
                SelectFilter::make('property_id')
                    ->label(__('Accommodation'))
                    ->relationship('property', 'name', Access::onlyOwn(...)),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('payment_link')
                    ->label(__('Payment link'))
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->authorize(fn (Booking $record): bool => static::canEdit($record))
                    ->visible(fn (Booking $record) => ! in_array($record->status, [BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::NoShow], true))
                    ->schema([
                        Select::make('type')
                            ->label(__('Payment type'))
                            ->options(fn (Booking $record) => collect([PaymentType::DownPayment, PaymentType::Balance, PaymentType::Deposit])
                                ->mapWithKeys(fn (PaymentType $type) => [$type->value => __($record->paymentKind($type))])
                                ->all())
                            ->default(PaymentType::DownPayment->value)
                            ->required(),
                        Toggle::make('mail')
                            ->label(__('Also email the link to the guest'))
                            ->helperText(fn (Booking $record) => PaymentLink::mailExplanation($record)),
                    ])
                    ->action(function (Booking $record, array $data) {
                        try {
                            $payment = app(PaymentEngine::class)->createPayment(
                                $record,
                                PaymentType::from($data['type']),
                            );

                            $link = $record->paymentLink($payment->type);

                            Notification::make()
                                ->title($payment->wasRecentlyCreated ? __('Payment link created') : __('Existing payment link used'))
                                ->body(Money::format($payment->amount_cents, $record->currency)." — {$link}")
                                ->success()
                                ->persistent()
                                ->actions([PaymentLink::copyAction('copy', $link)])
                                ->send();

                            if ($data['mail'] ?? false) {
                                PaymentLink::mail($payment);
                            }
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('Failed to create payment link'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                ActionGroup::make([
                    Action::make('invoice')
                        ->label(__('Invoice (PDF)'))
                        ->icon(Heroicon::OutlinedDocumentText)
                        // The first download assigns the next invoice number: that is
                        // a change, so view-only users only get an existing invoice.
                        ->authorize(fn (Booking $record): bool => static::canEdit($record)
                            || \App\Models\Invoice::query()->where('booking_id', $record->id)->exists())
                        ->action(function (Booking $record) {
                            $engine = app(\App\Engines\DocumentEngine::class);
                            $invoice = $engine->findOrCreateInvoice($record);

                            return response()->streamDownload(
                                fn () => print($engine->invoice($record)),
                                "{$invoice->invoice_number}.pdf",
                            );
                        }),
                    Action::make('confirmation_pdf')
                        ->label(__('Confirmation (PDF)'))
                        ->icon(Heroicon::OutlinedDocumentCheck)
                        ->action(fn (Booking $record) => response()->streamDownload(
                            fn () => print(app(\App\Engines\DocumentEngine::class)->confirmation($record)),
                            "confirmation-{$record->booking_number}.pdf",
                        )),
                ])
                    ->label(__('Documents'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->iconButton()
                    ->color('gray')
                    ->tooltip(__('Documents')),
                ActionGroup::make(
                    collect(BookingStatus::cases())
                        ->map(fn (BookingStatus $status) => Action::make('to_'.$status->value)
                            ->label($status->label())
                            ->icon(match ($status) {
                                BookingStatus::Confirmed => Heroicon::OutlinedCheck,
                                BookingStatus::Paid => Heroicon::OutlinedBanknotes,
                                BookingStatus::Cancelled => Heroicon::OutlinedXMark,
                                BookingStatus::Completed => Heroicon::OutlinedFlag,
                                BookingStatus::NoShow => Heroicon::OutlinedEyeSlash,
                                default => Heroicon::OutlinedArrowRight,
                            })
                            ->color($status->color())
                            ->requiresConfirmation()
                            ->modalHeading(fn (Booking $record) => "Boeking {$record->booking_number} naar '{$status->label()}'?")
                            ->authorize(fn (Booking $record): bool => static::canEdit($record))
                            ->visible(fn (Booking $record) => $record->status->canTransitionTo($status))
                            ->action(fn (Booking $record) => app(BookingEngine::class)->transition($record, $status)))
                        ->all()
                )
                    ->label(__('Status'))
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->iconButton()
                    ->color('gray')
                    ->tooltip(__('Status')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'create' => CreateBooking::route('/create'),
            'edit' => EditBooking::route('/{record}/edit'),
        ];
    }
}
