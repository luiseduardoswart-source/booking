{{-- Info in the "Automatic emails" tab: which email goes out when. --}}
<div style="display: grid; gap: 1rem;">
    <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, .35);">
                <th style="padding: .5rem .75rem .5rem 0; font-weight: 600;">{{ __('Email') }}</th>
                <th style="padding: .5rem 0; font-weight: 600;">{{ __('When it is sent') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach (\App\Enums\MailType::cases() as $type)
                <tr style="border-bottom: 1px solid rgba(127, 127, 127, .2); vertical-align: top;" data-mail-type="{{ $type->value }}">
                    <td style="padding: .5rem .75rem .5rem 0; font-weight: 500; white-space: nowrap;">{{ __($type->label()) }}</td>
                    <td style="padding: .5rem 0;">{{ __($type->moment()) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p style="font-size: .875rem; opacity: .75;">{{ __('Emails with a number of days are checked once a day and go out once per booking. Not for bookings that are cancelled, a no-show or not yet confirmed.') }}</p>
</div>
