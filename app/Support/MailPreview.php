<?php

namespace App\Support;

use App\Engines\PricingEngine;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;

/**
 * The standard text of an automatic email, as a guest of this accommodation
 * would get it: for an example booking that is never saved, with a marker
 * where the owner's own text goes.
 */
final class MailPreview
{
    /** Example stay: a month ahead, three nights. */
    private const DAYS_AHEAD = 30;

    private const NIGHTS = 3;

    public static function html(Property $property, MailType $type, string $locale): string
    {
        $marker = '**['.__('Your own text appears here', [], $locale).']**';

        // A copy, so the form's record keeps its own texts.
        $accommodation = clone $property;
        $accommodation->mail_texts = [$type->value => [$locale => $marker]];

        $previous = [app()->getLocale(), Carbon::getLocale(), CarbonImmutable::getLocale()];
        app()->setLocale($locale);

        try {
            $booking = self::exampleBooking($accommodation, $locale);
            $mail = new BookingMail($booking, $type, self::examplePayment($booking, $type));
            $content = $mail->content();

            // Only the email itself: Mailable::render() would also build the PDF attachments.
            return (string) app(Markdown::class)->render($content->markdown, [
                'booking' => $booking,
                'type' => $type,
                'payment' => $mail->payment,
                ...$content->with,
            ]);
        } finally {
            // The email templates set the date language themselves; give the page its own back.
            app()->setLocale($previous[0]);
            Carbon::setLocale($previous[1]);
            CarbonImmutable::setLocale($previous[2]);
        }
    }

    private static function exampleBooking(Property $property, string $locale): Booking
    {
        $arrival = CarbonImmutable::today()->addDays(self::DAYS_AHEAD);
        $quote = app(PricingEngine::class)->quote(
            $property,
            $arrival,
            $arrival->addDays(self::NIGHTS),
            min(2, max(1, (int) $property->max_guests)),
        );

        $booking = new Booking;
        $booking->forceFill([
            // Id 0: the links in the email point to no real booking.
            'id' => 0,
            'property_id' => $property->id,
            'booking_number' => sprintf('BK-%d-0000', $arrival->year),
            'locale' => $locale,
            'guest_name' => __('Example guest', [], $locale),
            'arrival_date' => $quote->arrival,
            'departure_date' => $quote->departure,
            'guests' => $quote->guests,
            'price_snapshot' => $quote->toArray(),
            'total_cents' => $quote->totalCents,
            'currency' => $quote->currency,
            'down_payment_cents' => $quote->downPaymentCents,
            'deposit_cents' => $quote->depositCents,
            'payment_status' => $quote->downPaymentCents >= $quote->totalCents ? PaymentStatus::Paid : PaymentStatus::DownPaymentPaid,
        ]);

        return $booking->setRelation('property', $property);
    }

    /** The payment the email is about, for the emails that name one. */
    private static function examplePayment(Booking $booking, MailType $type): ?Payment
    {
        $paymentType = match ($type) {
            MailType::PaymentReceived, MailType::Verified => PaymentType::DownPayment,
            MailType::PaymentRequest => $booking->paysInFull() ? PaymentType::DownPayment : PaymentType::Balance,
            default => null,
        };

        if ($paymentType === null) {
            return null;
        }

        $payment = new Payment;
        $payment->forceFill([
            'type' => $paymentType,
            'amount_cents' => $paymentType === PaymentType::Balance
                ? $booking->total_cents - $booking->down_payment_cents
                : $booking->down_payment_cents,
            'deposit_part_cents' => 0,
        ]);

        return $payment->setRelation('booking', $booking);
    }
}
