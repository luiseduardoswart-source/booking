<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Daily reminder to the admin: these deposits can be refunded.
 */
class DepositReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Collection $deposits) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Refund deposits: :count outstanding', ['count' => $this->deposits->count()]));
    }

    public function content(): Content
    {
        $rules = $this->deposits->map(fn ($deposit) => '<li>'
            .e($deposit->booking->booking_number).' — '.e($deposit->booking->property->name)
            .' — '.e($deposit->booking->guest_name)
            .' — '.e(__('departed :date', ['date' => $deposit->booking->departure_date->format('d-m-Y')]))
            .' — '.\App\Support\Money::format($deposit->amount_cents, $deposit->booking->currency)
            .'</li>')->implode('');

        return new Content(
            htmlString: '<p>'.e(__('The following deposits are ready to be refunded (via admin → Payments → Refund):')).'</p><ul>'.$rules.'</ul>'
                .'<p>'.e(__('Tip: turn on ":setting" in the settings to do this automatically.', ['setting' => __('Refund deposit automatically')])).'</p>',
        );
    }
}
