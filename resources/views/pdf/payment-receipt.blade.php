<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.style')
</head>
<body>
    @include('pdf.partials.heading', ['title' => __('Payment receipt')])

    <table class="details">
        <tr>
            <td width="50%">
                <strong>{{ __('Received from') }}</strong><br>
                {{ $booking->guest_name }}<br>
                {{ $booking->guest_email }}
            </td>
            <td width="50%">
                <strong>{{ __('Booking number') }}:</strong> {{ $booking->booking_number }}<br>
                <strong>{{ __('Paid on') }}:</strong> {{ $payment->paid_at?->format('d-m-Y H:i') ?? '—' }}<br>
                <strong>{{ __('Payment method') }}:</strong> {{ ucfirst($payment->provider) }}
            </td>
        </tr>
    </table>

    <table class="rules">
        <tbody>
            <tr>
                <td>{{ __(':kind for :accommodation', ['kind' => $booking->paymentKind($payment->type), 'accommodation' => $booking->property->localized('name')]) }}
                    ({{ __(':of to :until', ['of' => $booking->arrival_date->format('d-m-Y'), 'until' => $booking->departure_date->format('d-m-Y')]) }})</td>
                <td class="amount">{{ \App\Support\Money::format($payment->amount_cents - $payment->deposit_part_cents, $payment->booking->currency) }}</td>
            </tr>
            {{-- Deposit paid along with the stay: its own line, refunded after the stay. --}}
            @if ($payment->deposit_part_cents > 0)
                <tr>
                    <td>{{ __('Deposit (refunded after your stay)') }}</td>
                    <td class="amount">{{ \App\Support\Money::format($payment->deposit_part_cents, $payment->booking->currency) }}</td>
                </tr>
            @endif
            <tr class="total">
                <td>{{ __('Amount received') }}</td>
                <td class="amount">{{ \App\Support\Money::format($payment->amount_cents, $payment->booking->currency) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="foot">{{ $company['name'] }} — {{ __('generated on :moment', ['moment' => now()->format('d-m-Y H:i')]) }}</div>
</body>
</html>
