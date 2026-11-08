{{-- Lines under the total: what has been paid, refunded and is still to be paid. Expects $booking. --}}
@php($overview = $booking->paymentOverview())
@if ($overview['paid'] > 0)
    <tr class="vat">
        <td>{{ __('Paid') }}</td>
        <td class="amount">{{ \App\Support\Money::format($overview['paid'], $booking->currency) }}</td>
    </tr>
@endif
@if ($overview['refunded'] > 0)
    <tr class="vat">
        <td>{{ __('Refunded') }}</td>
        <td class="amount">- {{ \App\Support\Money::format($overview['refunded'], $booking->currency) }}</td>
    </tr>
@endif
@unless (in_array($booking->status->value, \App\Models\Booking::INACTIVE_STATUSES, true))
    <tr class="total">
        <td>{{ __('Amount due') }}</td>
        <td class="amount">{{ \App\Support\Money::format($overview['outstanding'], $booking->currency) }}</td>
    </tr>
@endunless
