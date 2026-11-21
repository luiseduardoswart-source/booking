@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Almost there — practical information for your arrival') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('We expect you at :accommodation on :date.', [
    'date' => $booking->arrival_date->translatedFormat('l j F Y'),
    'accommodation' => $property->localized('name'),
]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
{{-- Each on a paragraph of its own: Markdown joins lines that follow each other. --}}
@if ($property->address)
**{{ __('Address') }}:** {{ $property->address }}, {{ $property->postal_code }} {{ $property->city }}

@endif
@if ($property->effective('check_in_from'))
**{{ __('Check-in from :time', ['time' => \Illuminate\Support\Carbon::parse($property->effective('check_in_from'))->format('H:i')]) }}**{{ $property->effective('check_in_until') ? ' ('.__('until :time', ['time' => \Illuminate\Support\Carbon::parse($property->effective('check_in_until'))->format('H:i')]).')' : '' }}

@endif
@if ($property->effective('check_out_until'))
**{{ __('Check-out: :time', ['time' => \Illuminate\Support\Carbon::parse($property->effective('check_out_until'))->format('H:i')]) }}**

@endif

@if ($houseRules = $property->houseRulesText(times: false))
## {{ __('House rules') }}

{{ $houseRules }}
@endif

{{ __('We wish you a wonderful stay!') }}

@include('mail.partials.booking-link')
{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
