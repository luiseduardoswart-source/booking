<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Payment — :number', ['number' => $booking->booking_number]) }}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #f5f5f4; }
        .card { background: #fff; padding: 2.5rem 3rem; border-radius: 1rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); text-align: center; max-width: 28rem; }
        h1 { font-size: 1.4rem; margin: 0 0 .5rem; }
        p { color: #57534e; line-height: 1.6; }
        .ok { color: #16a34a; } .wait { color: #d97706; } .fail { color: #dc2626; }
        .deposit { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e7e5e4; }
        .button { display: inline-block; margin-top: .75rem; padding: .75rem 1.5rem; border-radius: .75rem; background: #e11d48; color: #fff; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
@php($number = '<strong>'.e($booking->booking_number).'</strong>')
<div class="card">
    @if ($payment->status === \App\Enums\PaymentTransactionStatus::Paid)
        <h1 class="ok">{{ __('Thank you, your payment has been received!') }}</h1>
        <p>{!! __('We have received your :kind for booking :number. You will receive a confirmation by email.', [
            'kind' => e(mb_strtolower($payment->kind())),
            'number' => $number,
        ]) !!}</p>
        @if ($depositLink)
            <div class="deposit">
                <p>{{ __('One more step: the deposit of :amount. It is a separate payment, not part of the price, and you get it back after your stay.', ['amount' => \App\Support\Money::format($booking->deposit_cents, $booking->currency)]) }}</p>
                <a class="button" href="{{ $depositLink }}">{{ __('Pay the deposit') }}</a>
            </div>
        @endif
    @elseif ($payment->status === \App\Enums\PaymentTransactionStatus::Open)
        <h1 class="wait">{{ __('Payment being processed') }}</h1>
        <p>{!! __('Your payment for booking :number is still being processed. You will be notified as soon as it is confirmed.', ['number' => $number]) !!}</p>
    @else
        <h1 class="fail">{{ __('Payment failed') }}</h1>
        <p>{!! __('The payment for booking :number is :status. Please contact us if you think this is wrong, and we will send you a new payment link.', [
            'number' => $number,
            'status' => e(mb_strtolower($payment->status->getLabel())),
        ]) !!}</p>
    @endif
</div>
</body>
</html>
