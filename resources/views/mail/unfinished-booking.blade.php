@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Your booking was not finished') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('You started a booking at :accommodation for :arrival to :departure, but the payment was not completed. We held the dates for you for an hour; they are available again now.', [
    'accommodation' => $property->localized('name'),
    'arrival' => $booking->arrival_date->translatedFormat('l j F'),
    'departure' => $booking->departure_date->translatedFormat('l j F Y'),
]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
@if (!empty($rebookUrl))
<x-mail::button :url="$rebookUrl">
{{ __('Book these dates again') }}
</x-mail::button>
@endif

{{ __('Nothing has been charged. Changed your mind? Then you do not need to do anything.') }}

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
