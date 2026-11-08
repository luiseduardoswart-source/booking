@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('Payment received') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('We have received your payment:') }}

@if (!empty($extraText))
{{ $extraText }}

@endif
@if ($payment)
**{{ $payment->kind() }}** — {{ \App\Support\Money::format($payment->amount_cents, $booking->currency) }}
@endif

{{ __('Booking') }}: **{{ $booking->booking_number }}** ({{ $property->localized('name') }},
{{ $booking->arrival_date->translatedFormat('j F') }} – {{ $booking->departure_date->translatedFormat('j F Y') }})

@if ($booking->payment_status === \App\Enums\PaymentStatus::Paid)
{{ __('Your stay is now fully paid. See you soon!') }}
@else
{{ __('Outstanding balance: :amount — due before arrival.', ['amount' => \App\Support\Money::format($booking->total_cents - $booking->down_payment_cents, $booking->currency)]) }}
@endif

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
