<?php

namespace App\Filament\Support;

use App\Engines\NotificationEngine;
use App\Enums\MailType;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Booking;
use App\Models\Language;
use App\Models\Payment;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;

/**
 * Copying and emailing the payment link, from the bookings and the payments.
 */
class PaymentLink
{
    /**
     * @param  string|Closure  $link  may depend on $record
     */
    public static function copyAction(string $name, string|Closure $link): Action
    {
        return Action::make($name)
            ->label(__('Copy link'))
            ->icon(Heroicon::OutlinedClipboard)
            ->alpineClickHandler(function (Action $action) use ($link): string {
                $text = Js::from($action->evaluate($link));
                $succeeded = Js::from(__('Copied'));
                $failed = Js::from(__('Copying failed'));

                return "copyText({$text}).then((ok) => \$tooltip(ok ? {$succeeded} : {$failed}, { theme: \$store.theme, timeout: 2000 }))";
            });
    }

    /**
     * Without $payment, it concerns the payment of the table row.
     */
    public static function mailAction(string $name, ?Payment $payment = null): Action
    {
        return Action::make($name)
            ->label(__('Email to guest'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->requiresConfirmation()
            ->modalHeading(__('Email the payment link to the guest?'))
            ->modalDescription(fn ($record) => self::mailExplanation(($payment ?? $record)->booking))
            ->authorize(fn ($record): bool => $record instanceof Booking
                ? BookingResource::canEdit($record)
                : PaymentResource::canEdit($record))
            ->action(fn ($record) => self::mail($payment ?? $record));
    }

    public static function mail(Payment $payment): void
    {
        $booking = $payment->booking;

        if (app(NotificationEngine::class)->send($booking, MailType::PaymentRequest, $payment)) {
            Notification::make()
                ->title(__('Payment link emailed to :email', ['email' => $booking->guest_email]))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Sending the email failed'))
            ->danger()
            ->send();
    }

    public static function mailExplanation(Booking $booking): string
    {
        $language = $booking->locale ?? config('app.locale');

        return __('To :email, in the guest\'s language (:language).', [
            'email' => $booking->guest_email,
            'language' => Language::allCached()->firstWhere('code', $language)?->name ?? $language,
        ]);
    }

    /**
     * The open payment links on the booking screen, each with a copy and mail button.
     */
    public static function section(): Section
    {
        return Section::make(__('Open payment links'))
            ->description(__('The guest can pay with this link. It keeps working, even if the payment page itself expires.'))
            ->visible(fn (?Booking $record): bool => $record?->openPayments()->isNotEmpty() ?? false)
            ->schema(fn (?Booking $record): array => $record?->openPayments()
                ->map(fn (Payment $payment): TextEntry => self::rule($record, $payment))
                ->all() ?? []);
    }

    private static function rule(Booking $booking, Payment $payment): TextEntry
    {
        $link = $booking->paymentLink($payment->type);

        return TextEntry::make("payment_link_{$payment->id}")
            ->label("{$payment->type->getLabel()}: ".Money::format($payment->amount_cents, $payment->booking->currency))
            ->state($link)
            ->afterContent([
                self::copyAction("copy_{$payment->id}", $link),
                self::mailAction("mail_{$payment->id}", $payment),
            ]);
    }
}
