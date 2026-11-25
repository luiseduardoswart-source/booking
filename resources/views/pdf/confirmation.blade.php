<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    @include('pdf.partials.style')
</head>
<body>
    @include('pdf.partials.heading', ['title' => __('Booking confirmation')])

    <table class="details">
        <tr>
            <td width="50%">
                <strong>{{ __('Guest') }}</strong><br>
                {{ $booking->guest_name }}<br>
                {{ $booking->guest_email }}
                @if ($booking->guest_phone)<br>{{ $booking->guest_phone }}@endif
            </td>
            <td width="50%">
                <strong>{{ __('Booking number') }}:</strong> {{ $booking->booking_number }}<br>
                <strong>{{ __('Status') }}:</strong> {{ $booking->status->label() }}<br>
                <strong>{{ __('Booked on') }}:</strong> {{ $booking->created_at->format('d-m-Y') }}
            </td>
        </tr>
    </table>

    <h2>{{ __('Stay') }}</h2>
    <table class="details">
        <tr>
            <td width="50%">
                <strong>{{ $booking->property->localized('name') }}</strong><br>
                @if ($booking->property->address){{ $booking->property->address }}<br>@endif
                @if ($booking->property->postal_code || $booking->property->city){{ $booking->property->postal_code }} {{ $booking->property->city }}<br>@endif
                @include('pdf.partials.registration')
            </td>
            <td width="50%">
                <strong>{{ __('Check-in') }}:</strong> {{ $booking->arrival_date->format('d-m-Y') }}
                @if ($booking->property->effective('check_in_from')) ({{ __('from :time', ['time' => \Illuminate\Support\Carbon::parse($booking->property->effective('check_in_from'))->format('H:i')]) }})@endif<br>
                <strong>{{ __('Check-out') }}:</strong> {{ $booking->departure_date->format('d-m-Y') }}
                @if ($booking->property->effective('check_out_until')) ({{ __('before :time', ['time' => \Illuminate\Support\Carbon::parse($booking->property->effective('check_out_until'))->format('H:i')]) }})@endif<br>
                <strong>{{ __('Guests') }}:</strong> {{ $booking->guests }}
            </td>
        </tr>
    </table>

    <h2>{{ __('Price breakdown') }}</h2>
    <table class="rules">
        <tbody>
            @foreach ($booking->price_snapshot['lines'] as $line)
                <tr>
                    <td>{{ $line['label'] }}</td>
                    <td class="amount">{{ \App\Support\Money::format($line['amount_cents'], $booking->currency) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>{{ __('Total') }}</td>
                <td class="amount">{{ \App\Support\Money::format($booking->total_cents, $booking->currency) }}</td>
            </tr>
            @include('pdf.partials.payment')
            @if ($booking->deposit_cents > 0)
                <tr class="vat">
                    <td>{{ __('Deposit (payable separately, returned after the stay)') }}</td>
                    <td class="amount">{{ \App\Support\Money::format($booking->deposit_cents, $booking->currency) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($houseRules = $booking->property->houseRulesText(times: false))
        <h2>{{ __('House rules') }}</h2>
        <p style="white-space: pre-line;">{{ $houseRules }}</p>
    @endif

    @if ($booking->terms_snapshot)
        <h2>{{ __('Accepted terms') }}</h2>
        <p style="white-space: pre-line;">{{ $booking->terms_snapshot }}</p>
        @if ($booking->terms_accepted_at)
            <p style="color: #78716c; font-size: 10px;">{{ __('Accepted on :moment', ['moment' => $booking->terms_accepted_at->format('d-m-Y H:i')]) }}</p>
        @endif

        @if ($booking->signed_at)
            <h2>{{ __('Digitally signed') }}</h2>
            @if ($signature = $booking->signatureAsDataUri())
                <img src="{{ $signature }}" alt="" style="height: 70px; border-bottom: 1px solid #a8a29e;">
            @endif
            <p>
                {{ $booking->signature_name }}<br>
                {{ __('Signed on :moment', ['moment' => $booking->signed_at->format('d-m-Y H:i')]) }}
                @if ($booking->signature_ip) &middot; IP {{ $booking->signature_ip }} @endif
            </p>
            <p style="color: #78716c; font-size: 9px; word-break: break-all;">
                {{ __('Fingerprint of the signed text (SHA-256)') }}: {{ $booking->signature_hash }}<br>
                @if ($booking->signedTextUnchanged())
                    {{ __('The text above has not changed since it was signed.') }}
                @else
                    {{ __('Warning: the text above does not match what was signed.') }}
                @endif
            </p>
        @endif
    @else
        @if ($booking->property->effectiveLocalized('rental_terms'))
            <h2>{{ __('Rental conditions') }}</h2>
            <p style="white-space: pre-line;">{{ $booking->property->effectiveLocalized('rental_terms') }}</p>
        @endif
        @if ($booking->property->cancellationPolicyLines() !== [])
            <h2>{{ __('Cancellation policy') }}</h2>
            <ul>
                @foreach ($booking->property->cancellationPolicyLines() as $rule)
                    <li>{{ $rule }}</li>
                @endforeach
            </ul>
        @endif
    @endif

    <div class="foot">{{ $company['name'] }} — {{ __('generated on :moment', ['moment' => now()->format('d-m-Y H:i')]) }}</div>
</body>
</html>
