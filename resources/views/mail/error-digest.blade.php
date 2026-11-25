@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Your website ran into problems') }}

{{ __('This is what went wrong since :moment. Guests may not have noticed anything, but have a look, or forward this email to whoever maintains your website.', ['moment' => $since->setTimezone(config('app.timezone'))->isoFormat('LLL')]) }}

@if ($backup_failed)
## {{ __('Backup') }}

{{ __('The nightly backup failed.') }} [{{ __('Open the backups') }}]({{ $backupsUrl }})

@endif
@if ($calendars->isNotEmpty())
## {{ __('Calendars that do not sync') }}

@foreach ($calendars as $calendar)
- **{{ \App\Support\Markdown::asText($calendar->property?->name) }}**, {{ \App\Support\Markdown::asText($calendar->name ?: $calendar->provider) }}: {{ \App\Support\Markdown::asText(\Illuminate\Support\Str::limit((string) $calendar->last_error, 200)) }}
@endforeach

{{ __('Until they sync again, bookings from these platforms do not block your calendar.') }} [{{ __('Open the calendar connections') }}]({{ $calendarsUrl }})

@endif
@if ($failed_jobs > 0)
## {{ __('Emails and tasks that failed') }}

{{ trans_choice('{1} 1 email or task could not be done, also not after trying again. Check the email settings under Settings → Email.|[2,*] :count emails or tasks could not be done, also not after trying again. Check the email settings under Settings → Email.', $failed_jobs) }}

@endif
@if ($reports->isNotEmpty())
## {{ __('Errors') }}

@foreach ($reports as $error)
- **{{ trans_choice('{1} once|[2,*] :count times', $error->occurrences) }}**, {{ __('last on :moment', ['moment' => $error->last_seen_at->setTimezone(config('app.timezone'))->isoFormat('LLL')]) }}: {{ \App\Support\Markdown::asText($error->message) }}@if ($error->location) ({{ \App\Support\Markdown::asText($error->location) }})@endif

@endforeach
@endif
{{ __('You get this email at most once a day, and only when something went wrong.') }}

{{ $company }}
</x-mail::message>
