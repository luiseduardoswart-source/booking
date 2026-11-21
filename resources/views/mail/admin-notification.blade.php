@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ $notification->title() }}

@if ($notification === \App\Enums\AdminNotification::IdentityMissing)
{{ __('The guest has not verified their identity by :date. You decide whether the booking goes ahead.', ['date' => $booking->identityDeadline()?->translatedFormat('j M Y')]) }}

@elseif ($notification === \App\Enums\AdminNotification::Cancellation && $booking->identity_overdue_at !== null)
{{ __('Cancelled automatically: the guest did not verify their identity in time.') }}

@endif
@if ($notification === \App\Enums\AdminNotification::PaymentReturned && $payment)
{{ __(':amount of a payment for this booking went back to the guest outside the website: a refund at the payment provider itself, or the guest had the payment reversed by the bank.', ['amount' => \App\Support\Money::format($payment->amount_cents, $booking->currency)]) }}

{{ __('The website has booked it as returned. While the stay is not paid in full, the check-in instructions and the door code are not sent. Check the payment at your payment provider.') }}

@endif
@if ($notification === \App\Enums\AdminNotification::Payment && $payment)
**{{ $payment->kind() }}**: {{ \App\Support\Money::format($payment->amount_cents, $booking->currency) }}

@endif
<x-mail::table>
| | |
|:--|:--|
| {{ __('Booking') }} | {{ $booking->booking_number }} |
| {{ __('Accommodation') }} | {{ $property->name }} |
| {{ __('Stay') }} | {{ $booking->arrival_date->translatedFormat('j M Y') }} – {{ $booking->departure_date->translatedFormat('j M Y') }} ({{ trans_choice('{1} 1 night|[2,*] :count nights', $booking->nights()) }}) |
| {{ __('Guest') }} | {{ $guestName }}, {{ trans_choice('{1} 1 guest|[2,*] :count guests', $booking->guests) }} |
| {{ __('Total') }} | {{ \App\Support\Money::format($booking->total_cents, $booking->currency) }} |
| {{ __('Payment') }} | {{ $booking->payment_status->getLabel() }} |
</x-mail::table>

<x-mail::button :url="$link">
{{ __('Open booking') }}
</x-mail::button>

{{ $company }}
</x-mail::message>
