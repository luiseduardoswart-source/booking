@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Information about your departure') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('Tomorrow your stay at :accommodation comes to an end.', ['accommodation' => $property->localized('name')]) }}

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

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
