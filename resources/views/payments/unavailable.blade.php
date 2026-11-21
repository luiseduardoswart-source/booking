<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Payment — :number', ['number' => $booking->booking_number]) }}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #f5f5f4; }
        .card { background: #fff; padding: 2.5rem 3rem; border-radius: 1rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); text-align: center; max-width: 28rem; }
        h1 { font-size: 1.4rem; margin: 0 0 .5rem; color: #d97706; }
        p { color: #57534e; line-height: 1.6; }
        .button { display: inline-flex; align-items: center; gap: .5rem; margin-top: .75rem; padding: .75rem 1.5rem; border-radius: .75rem; background: #e11d48; color: #fff; font-weight: 600; text-decoration: none; }
        .button svg { width: 1.25rem; height: 1.25rem; }
    </style>
</head>
<body>
{{-- The provider could not make the checkout. The booking is held, not confirmed. --}}
<div class="card">
    <h1>{{ __('The payment could not be started') }}</h1>
    <p>{!! __('We are holding booking :number for you, but the payment page could not be opened. Your booking is only final once it is paid. Please try again in a few minutes.', [
        'number' => '<strong>'.e($booking->booking_number).'</strong>',
    ]) !!}</p>
    <a class="button" href="{{ $retryUrl }}">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
        {{ __('Try again') }}
    </a>
</div>
</body>
</html>
