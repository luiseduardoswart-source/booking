@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Thank you for your booking, :name!', ['name' => $guestName]) }}

{{ __('We have received your reservation with number :number.', ['number' => $booking->booking_number]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
{{-- Sent after the first payment: that payment, so no separate "payment received". --}}
@if ($payment)
{{ __('We have received your payment:') }} **{{ $payment->kind() }}** — {{ \App\Support\Money::format($payment->amount_cents, $booking->currency) }}

@endif
**{{ $property->localized('name') }}**<br>
{{ __('Check-in') }}: **{{ $booking->arrival_date->translatedFormat('l j F Y') }}**<br>
{{ __('Check-out') }}: **{{ $booking->departure_date->translatedFormat('l j F Y') }}**<br>
{{ __('Guests') }}: **{{ $booking->guests }}**

## {{ __('Price breakdown') }}

<x-mail::table>
| {{ __('Description') }} | {{ __('Amount') }} |
|:------------ | ------:|
@foreach ($booking->price_snapshot['lines'] as $line)
| {{ $line['label'] }} | {{ \App\Support\Money::format($line['amount_cents'], $booking->currency) }} |
@endforeach
| **{{ __('Total') }}** | **{{ \App\Support\Money::format($booking->total_cents, $booking->currency) }}** |
</x-mail::table>

@if ($booking->deposit_cents > 0)
{{ __('Deposit (separate, refunded afterwards): :amount', ['amount' => \App\Support\Money::format($booking->deposit_cents, $booking->currency)]) }}
@endif

@if ($booking->down_payment_cents < $booking->total_cents)
{{ __('The down payment is :amount.', ['amount' => \App\Support\Money::format($booking->down_payment_cents, $booking->currency)]) }}
{{ __('The balance of :amount is due before arrival.', ['amount' => \App\Support\Money::format($booking->total_cents - $booking->down_payment_cents, $booking->currency)]) }}
@endif

@if ($booking->property->cancellationPolicyLines() !== [])
## {{ __('Cancellation policy') }}

@foreach ($booking->property->cancellationPolicyLines() as $rule)
- {{ $rule }}
@endforeach
@endif

@if ($idVerification ?? false)
## {{ __('Verify your identity') }}

{{ __('To complete your booking, we ask you to verify your identity. It takes about a minute and runs in a secure environment — we never see your document itself.') }}

@if ($identityDeadline ?? null)
**{{ __('Please do this by :date at the latest.', ['date' => $identityDeadline->translatedFormat('l j F Y')]) }}**
@if ($property->idOverdueCancels())
{{ __('Without it, your booking will be cancelled.') }}
@endif

@endif
<x-mail::button :url="$verificationUrl">
{{ __('Verify your identity') }}
</x-mail::button>
@endif

{{ __('Questions or changes? Reply to this email and we will be happy to help.') }}

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
