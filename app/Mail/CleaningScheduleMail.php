<?php

namespace App\Mail;

use App\Models\Cleaner;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * The changeovers of the coming days for a cleaner, in their language (see
 * App\Support\CleaningSchedule). Sent straight away by cleaning:send.
 */
class CleaningScheduleMail extends Mailable
{
    use Queueable;

    public function __construct(public Cleaner $cleaner, public Collection $changeovers)
    {
        $this->locale($cleaner->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans_choice(
            '{1} Cleaning: 1 changeover in the coming :days days|[2,*] Cleaning: :count changeovers in the coming :days days',
            $this->changeovers->count(),
            ['days' => $this->cleaner->daysAhead()],
        ));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.cleaning-schedule', with: [
            'scheduleUrl' => $this->cleaner->scheduleUrl(),
            'company' => Setting::get('company', 'name', config('app.name')),
        ]);
    }
}
