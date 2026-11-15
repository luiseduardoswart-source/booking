@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Your booking has been cancelled') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('We did not receive the verification of your identity in time, so your booking :number at :accommodation (:date) has been cancelled.', [
    'number' => $booking->booking_number,
    'accommodation' => $property->localized('name'),
    'date' => $booking->arrival_date->translatedFormat('l j F Y'),
]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
@if ($property->idCancelRefund() === \App\Models\Property::ID_REFUND_FULL)
{{ __('We are refunding everything you paid.') }}
@elseif ($property->idCancelRefund() === \App\Models\Property::ID_REFUND_DEPOSIT && $booking->deposit_cents > 0)
{{ __('We are refunding your deposit; the amount paid for the stay is not refunded.') }}
@elseif ($property->idCancelRefund() === \App\Models\Property::ID_REFUND_DEPOSIT)
{{ __('The amount paid for the stay is not refunded.') }}
@else
{{ __('We will contact you about any refund.') }}
@endif

{{ __('Questions or changes? Reply to this email and we will be happy to help.') }}

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
