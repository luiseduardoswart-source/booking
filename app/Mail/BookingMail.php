<?php

namespace App\Mail;

use App\Enums\MailType;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\MailPlaceholders;
use App\Support\Markdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class BookingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public MailType $type,
        public ?Payment $payment = null,
    ) {
        $this->afterCommit();

        // Mails go out in the language the guest used when booking.
        $this->locale($booking->locale ?? config('app.locale'));
    }

    public function envelope(): Envelope
    {
        $number = ['number' => $this->booking->booking_number];

        $subject = match ($this->type) {
            MailType::Confirmation => __('Confirmation of your booking :number', $number),
            MailType::PaymentReceived => __('Payment received for booking :number', $number),
            MailType::BalancePayment => __('Reminder: balance payment for your stay (:number)', $number),
            MailType::Deposit => __('Deposit for your stay (:number)', $number),
            MailType::PaymentRequest => __('Payment request for your booking :number', $number),
            MailType::Verified => __('Verification successful — complete your booking (:number)', $number),
            MailType::Checkin => __('Practical information for your arrival (:number)', $number),
            MailType::Checkout => __('Information about your departure (:number)', $number),
            MailType::Review => __('How was your stay?'),
            MailType::IdentityReminder => __('Please verify your identity before your stay (:number)', $number),
            MailType::IdentityCancelled => __('Your booking :number has been cancelled', $number),
        };

        return new Envelope(subject: $subject);
    }

    /**
     * PDF attachments: the confirmation with the booking mail (plus the receipt
     * when it comes with the first payment), the proof of
     * payment with the payment mail.
     */
    public function attachments(): array
    {
        $documents = app(\App\Engines\DocumentEngine::class);

        // The confirmation that comes with the first payment carries its receipt too.
        $receipt = $this->payment === null ? [] : [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $documents->receipt($this->payment),
                "payment_receipt-{$this->booking->booking_number}.pdf",
            )->withMime('application/pdf'),
        ];

        return match ($this->type) {
            MailType::Confirmation => [
                \Illuminate\Mail\Mailables\Attachment::fromData(
                    fn () => $documents->confirmation($this->booking),
                    "confirmation-{$this->booking->booking_number}.pdf",
                )->withMime('application/pdf'),
                ...$receipt,
            ],
            MailType::PaymentReceived => $receipt,
            default => [],
        };
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.'.str_replace('_', '-', $this->type->value),
            with: [
                'booking' => $this->booking,
                // The name comes from the guest themselves: no formatting or links.
                'guestName' => Markdown::asText($this->booking->guest_name),
                'property' => $this->booking->property,
                'payment' => $this->payment,
                'company' => Setting::get('company', 'name', config('app.name')),
                // Custom text per mail type and per language, per accommodation or as
                // default, with the merge fields ([name], [phone]...) filled in.
                'extraText' => ($text = $this->booking->property->mailText($this->type)) === null
                    ? null
                    : MailPlaceholders::fillIn($text, $this->booking),
                'payBalanceUrl' => $this->booking->paymentLink(PaymentType::Balance),
                'reviewUrl' => URL::signedRoute('reviews.create', $this->booking),
                'payDepositUrl' => $this->booking->paymentLink(PaymentType::Deposit),
                'idVerification' => $this->booking->identityMissing(),
                // With the check after the payment: the last day to do it.
                'identityDeadline' => $this->booking->identityDeadline(),
                'verificationUrl' => URL::signedRoute('identity.start', $this->booking),
            ],
        );
    }
}
