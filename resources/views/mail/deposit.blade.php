@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Deposit for your stay') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('For your stay at :accommodation (:date) we ask for a deposit of :amount.', [
    'accommodation' => $property->localized('name'),
    'date' => $booking->arrival_date->translatedFormat('l j F Y'),
    'amount' => \App\Support\Money::format($booking->deposit_cents, $booking->currency),
]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
<x-mail::button :url="$payDepositUrl">
{{ __('Pay the deposit') }}
</x-mail::button>

{{ __('The deposit will be refunded after your stay, provided everything is in order.') }}

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
