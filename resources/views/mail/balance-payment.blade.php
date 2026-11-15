@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Reminder: balance payment') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('Your stay at :accommodation is coming up (:date). A balance payment is still due for booking :number.', [
    'accommodation' => $property->localized('name'),
    'date' => $booking->arrival_date->translatedFormat('l j F Y'),
    'number' => $booking->booking_number,
]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
<x-mail::button :url="$payBalanceUrl">
{{ __('Pay the balance') }}
</x-mail::button>

{{ __('Button not working? Reply to this email and we will send you a new payment link.') }}

@include('mail.partials.booking-link')
{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
