{{-- One submission in the admin: label and answer per field, everything as plain text. --}}
@php($form = $submission->form)
<dl style="display: grid; gap: 1rem;">
    @foreach ((array) $submission->data as $answer)
        @continue(($answer['type'] ?? null) === 'heading')
        <div>
            <dt style="font-weight: 600;">{{ \App\Support\FormAnswers::label($answer, $form) }}</dt>
            <dd style="margin-top: .25rem; white-space: pre-line;">{{ \App\Support\FormAnswers::value($answer, $form) ?: '—' }}</dd>
        </div>
    @endforeach
    @if ($reply = $submission->replyAddress())
        <div>
            <a href="mailto:{{ $reply }}" style="text-decoration: underline;">{{ __('Reply by email') }}</a>
        </div>
    @endif
</dl>
