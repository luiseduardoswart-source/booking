<?php

namespace App\Mail;

use App\Enums\AdminNotification;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\AdminLanguages;
use App\Support\Markdown;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notification for owner and host (see App\Support\AdminNotifications). If it
 * goes to an account, it is in that account's language (Laravel picks up
 * User::preferredLocale); otherwise in the default admin language, never in the guest's.
 */
class AdminNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AdminNotification $notification,
        public Booking $booking,
        public ?Payment $payment = null,
    ) {
        $this->locale(AdminLanguages::default());
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->subject($this->booking));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.admin-notification', with: [
            'property' => $this->booking->property,
            // The name comes from the guest: without formatting, links or an
            // extra table column.
            'guestName' => Markdown::asText($this->booking->guest_name),
            'link' => route('filament.admin.resources.bookings.edit', $this->booking),
            'company' => Setting::get('company', 'name', config('app.name')),
        ]);
    }
}
