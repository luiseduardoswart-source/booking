<?php

namespace App\Mail;

use App\Models\Setting;
use App\Support\AdminLanguages;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The daily email to the owner about what went wrong (see
 * App\Support\ErrorReports). In the owner's own language when it goes to
 * their account, otherwise in the default admin language.
 */
class ErrorDigestMail extends Mailable
{
    use Queueable;

    /** @param array<string, mixed> $digest */
    public function __construct(public array $digest, public CarbonImmutable $since)
    {
        $this->locale(AdminLanguages::default());
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your website ran into problems'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.error-digest', with: [
            ...$this->digest,
            'since' => $this->since,
            'company' => Setting::get('company', 'name', config('app.name')),
            'calendarsUrl' => route('filament.admin.resources.channel-connections.index'),
            'backupsUrl' => route('filament.admin.pages.backups'),
        ]);
    }
}
