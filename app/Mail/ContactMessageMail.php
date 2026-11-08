<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $messageText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nieuw bericht via het contactformulier',
            replyTo: [new \Illuminate\Mail\Mailables\Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p><strong>Naam:</strong> '.e($this->senderName).'<br>'
                .'<strong>E-mail:</strong> '.e($this->senderEmail).'</p>'
                .'<p>'.nl2br(e($this->messageText)).'</p>',
        );
    }
}
