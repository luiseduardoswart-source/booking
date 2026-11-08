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
@if ($property->address)
**{{ __('Address') }}:** {{ $property->address }}, {{ $property->postal_code }} {{ $property->city }}
@endif
@if ($property->effective('check_in_from'))
**{{ __('Check-in from :time', ['time' => \Illuminate\Support\Carbon::parse($property->effective('check_in_from'))->format('H:i')]) }}**@if ($property->effective('check_in_until')) ({{ __('until :time', ['time' => \Illuminate\Support\Carbon::parse($property->effective('check_in_until'))->format('H:i')]) }})@endif
@endif
@if ($property->effective('check_out_until'))
**{{ __('Check-out before :time', ['time' => \Illuminate\Support\Carbon::parse($property->effective('check_out_until'))->format('H:i')]) }}**
@endif

@if ($property->effectiveLocalized('house_rules'))
## {{ __('House rules') }}

{{ $property->effectiveLocalized('house_rules') }}
@endif

{{ __('We wish you a wonderful stay!') }}

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
