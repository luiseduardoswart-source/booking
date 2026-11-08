<x-mail::message>
# {{ __('Payment request') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('A payment is open for booking :number at :accommodation: :kind of :amount.', [
    'number' => $booking->booking_number,
    'accommodation' => $property->localized('name'),
    'kind' => $payment->kind(),
    'amount' => \App\Support\Money::format($payment->amount_cents, $booking->currency),
]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
<x-mail::button :url="$booking->paymentLink($payment->type)">
{{ __('Pay now') }}
</x-mail::button>

{{ __('Button not working? Reply to this email and we will send you a new payment link.') }}

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
