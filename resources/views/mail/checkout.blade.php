@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Information about your departure') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('Your stay at :accommodation ends on :date.', [
    'accommodation' => $property->localized('name'),
    'date' => $booking->departure_date->translatedFormat('l j F Y'),
]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
@if ($property->effective('check_out_until'))
{{ __('On the day of departure, please check out by :time.', ['time' => \Illuminate\Support\Carbon::parse($property->effective('check_out_until'))->format('H:i')]) }}
@endif

{{ __('Please leave the accommodation tidy and put the key in the agreed place.') }}

@if ($booking->deposit_cents > 0)
{{ __('The deposit of :amount will be refunded after inspection of the accommodation.', ['amount' => \App\Support\Money::format($booking->deposit_cents, $booking->currency)]) }}
@endif

{{ __('Thank you for your stay — we hope to welcome you again!') }}

@include('mail.partials.booking-link')
{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
