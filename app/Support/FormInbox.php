<?php

namespace App\Support;

use App\Mail\FormSubmissionMail;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Receives a sent form: stores the answers and mails them to the form's
 * recipients. A mail that fails does not lose the submission: it is in the
 * admin under Submissions.
 */
final class FormInbox
{
    /** @param  array<int, array<string, mixed>>  $answers */
    public static function receive(Form $form, array $answers): FormSubmission
    {
        $submission = $form->submissions()->create([
            'data' => $answers,
            'locale' => app()->getLocale(),
        ]);

        try {
            Mail::to($form->recipientList())->send(new FormSubmissionMail($submission));
        } catch (Throwable $e) {
            report($e);
        }

        return $submission;
    }
}
