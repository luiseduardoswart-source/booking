<?php

namespace App\Mail;

use App\Models\Setting;
use App\Support\GuestAccess;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one-time login link for "My bookings", in the language of the page
 * the guest asked for it on. Sent right away (not queued): the guest waits for it.
 */
class GuestLoginMail extends Mailable
{
    public function __construct(public string $token, string $locale)
    {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your login link for My bookings'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.guest-login', with: [
            'url' => route('guest.login', ['token' => $this->token]),
            'minutes' => GuestAccess::LINK_MINUTES,
            'company' => Setting::get('company', 'name', config('app.name')),
        ]);
    }
}
