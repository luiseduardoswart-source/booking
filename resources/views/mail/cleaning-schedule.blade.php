@php
    \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale());
    $time = fn (?string $value): ?string => filled($value) ? \Illuminate\Support\Carbon::parse($value)->format('H:i') : null;
    $people = fn (array $stay): ?string => $stay['guests'] === null ? null : trans_choice('{1} 1 person|[2,*] :count people', $stay['guests'])
        .($stay['pets'] ? ', '.trans_choice('{1} 1 pet|[2,*] :count pets', $stay['pets']) : '');
@endphp
<x-mail::message>
# {{ __('Cleaning schedule') }}

{{ __('Hello :name,', ['name' => \App\Support\Markdown::asText($cleaner->name)]) }}

{{ __('These are the changeovers of the coming :days days.', ['days' => $cleaner->daysAhead()]) }}

@foreach ($changeovers as $changeover)
**{{ ucfirst($changeover['date']->translatedFormat('l j F')) }}: {{ \App\Support\Markdown::asText($changeover['property']->name) }}**@if ($changeover['same_day']) ({{ __('Same-day changeover') }})@endif<br>
{{ __('Departure') }}@if ($checkOut = $time($changeover['property']->effective('check_out_until'))) {{ __('before :time', ['time' => $checkOut]) }}@endif: {{ collect([$people($changeover['out']), $changeover['out']['source']])->filter()->implode(' · ') }}<br>
@if ($changeover['next'] === null)
{{ __('Next arrival') }}: {{ __('None planned yet') }}
@else
{{ __('Next arrival') }}: {{ ucfirst($changeover['next']['arrival']->translatedFormat('l j F')) }}@if ($checkIn = $time($changeover['property']->effective('check_in_from'))) {{ __('from :time', ['time' => $checkIn]) }}@endif, {{ collect([$people($changeover['next']), $changeover['next']['source']])->filter()->implode(' · ') }}
@endif

@endforeach
<x-mail::button :url="$scheduleUrl">
{{ __('Open your cleaning schedule') }}
</x-mail::button>

{{ __('The page is always up to date. Keep the link to yourself: it opens your schedule without a password.') }}

{{ $company }}
</x-mail::message>
