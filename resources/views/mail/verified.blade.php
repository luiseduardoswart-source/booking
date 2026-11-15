@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('You are verified!') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

@if ($booking->paysInFull())
{{ __('Your identity verification was successful. Complete your booking by paying the amount.') }}
@else
{{ __('Your identity verification was successful. Complete your booking by paying the down payment.') }}
@endif

@if (!empty($extraText))
{{ $extraText }}

@endif
@if ($payment)
<x-mail::button :url="$booking->paymentLink($payment->type)">
{{ $booking->paysInFull() ? __('Pay now') : __('Pay the down payment') }}
</x-mail::button>
@endif

{{ __('Booking') }}: **{{ $booking->booking_number }}** ({{ $property->localized('name') }},
{{ $booking->arrival_date->translatedFormat('j F') }} – {{ $booking->departure_date->translatedFormat('j F Y') }})

@include('mail.partials.booking-link')
{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
