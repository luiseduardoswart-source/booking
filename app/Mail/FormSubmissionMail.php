<?php

namespace App\Mail;

use App\Models\FormSubmission;
use App\Models\Setting;
use App\Support\AdminLanguages;
use App\Support\FormAnswers;
use App\Support\Markdown;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A form was sent on the site: the answers to the recipients of that form, in
 * the admin language. "Reply" goes to the email address the visitor gave.
 */
class FormSubmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FormSubmission $submission)
    {
        $this->locale(AdminLanguages::default());
    }

    public function envelope(): Envelope
    {
        $reply = $this->submission->replyAddress();

        return new Envelope(
            subject: __('New message via :form', ['form' => $this->submission->form->name]),
            replyTo: $reply ? [new Address($reply)] : [],
        );
    }

    public function content(): Content
    {
        $form = $this->submission->form;

        return new Content(markdown: 'mail.form-submission', with: [
            'formName' => Markdown::asText($form->name),
            // Everything comes from the visitor: plain text, no formatting or links.
            'rows' => collect($this->submission->data)
                ->reject(fn (array $answer) => ($answer['type'] ?? null) === 'heading')
                ->map(fn (array $answer) => [
                    'label' => Markdown::asText(FormAnswers::label($answer, $form)),
                    'value' => Markdown::asText(FormAnswers::value($answer, $form)) ?: '—',
                ])->all(),
            'link' => route('filament.admin.resources.form-submissions.index'),
            'company' => Setting::get('company', 'name', config('app.name')),
        ]);
    }
}
