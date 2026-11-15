@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Please verify your identity') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('Your stay at :accommodation starts on :date. Before then we ask you to verify your identity.', [
    'accommodation' => $property->localized('name'),
    'date' => $booking->arrival_date->translatedFormat('l j F Y'),
]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
@if ($identityDeadline)
**{{ __('Please do this by :date at the latest.', ['date' => $identityDeadline->translatedFormat('l j F Y')]) }}**
@if ($property->idOverdueCancels())
{{ __('Without it, your booking will be cancelled.') }}
@endif

@endif
<x-mail::button :url="$verificationUrl">
{{ __('Verify your identity') }}
</x-mail::button>

{{ __('It takes about a minute and runs in a secure environment — we never see your document itself.') }}

@include('mail.partials.booking-link')
{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
