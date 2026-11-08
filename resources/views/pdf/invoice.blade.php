<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.style')
</head>
<body>
    @include('pdf.partials.heading', ['title' => __('Invoice')])

    <table class="details">
        <tr>
            <td width="50%">
                <strong>{{ __('Invoice to') }}</strong><br>
                {{ $booking->guest_name }}<br>
                @if ($booking->guest_address){{ $booking->guest_address }}<br>@endif
                @if ($booking->guest_postal_code || $booking->guest_city){{ $booking->guest_postal_code }} {{ $booking->guest_city }}<br>@endif
                {{ $booking->guest_email }}
            </td>
            <td width="50%">
                <strong>{{ __('Invoice number') }}:</strong> {{ $invoice->invoice_number }}<br>
                <strong>{{ __('Invoice date') }}:</strong> {{ $invoice->issued_at->format('d-m-Y') }}<br>
                <strong>{{ __('Booking number') }}:</strong> {{ $booking->booking_number }}
            </td>
        </tr>
    </table>

    <h2>{{ $booking->property->localized('name') }} — {{ __(':of to :until', ['of' => $booking->arrival_date->format('d-m-Y'), 'until' => $booking->departure_date->format('d-m-Y')]) }} ({{ __(':count guests', ['count' => $booking->guests]) }})</h2>

    <table class="rules">
        <thead>
            <tr><th>{{ __('Description') }}</th><th class="amount">{{ __('Amount') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($booking->price_snapshot['lines'] as $line)
                <tr>
                    <td>{{ $line['label'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($line['amount_cents'], $booking->currency) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>{{ __('Total (incl. VAT)') }}</td>
                <td class="amount">{{ \App\Support\Money::format($invoice->total_cents, $booking->currency) }}</td>
            </tr>
            <tr class="vat">
                <td>{{ __('Of which :percentage% VAT', ['percentage' => $invoice->vat_percentage]) }}</td>
                <td class="amount">{{ \App\Support\Money::format($invoice->vat_cents, $booking->currency) }}</td>
            </tr>
            @include('pdf.partials.payment')
        </tbody>
    </table>

    <div class="foot">
        @if ($company['iban']){{ __('Payment to :iban in the name of :name, quoting :number.', ['iban' => $company['iban'], 'name' => $company['name'], 'number' => $invoice->invoice_number]) }}<br>@endif
        {{ $company['name'] }} — {{ __('generated on :moment', ['moment' => now()->format('d-m-Y H:i')]) }}
    </div>
</body>
</html>
