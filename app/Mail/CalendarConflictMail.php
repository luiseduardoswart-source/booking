<?php

namespace App\Mail;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CalendarConflictMail extends Mailable
{
    /**
     * @param  string[]  $conflicts
     */
    public function __construct(
        public Property $property,
        public array $conflicts,
    ) {}

    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: '⚠ '.__('Calendar conflict detected — :name', ['name' => $this->property->name]));
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>'.__('<strong>Note!</strong> Double bookings were detected at :name:', ['name' => e($this->property->name)]).'</p><ul><li>'
                .implode('</li><li>', array_map('e', $this->conflicts))
                .'</li></ul><p>'.e(__('Contact the guest or the platform right away to resolve this.')).'</p>',
        );
    }
}
