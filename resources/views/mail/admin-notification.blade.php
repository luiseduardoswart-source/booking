@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ $notification->title() }}

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
